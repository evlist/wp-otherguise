<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The requests to the Wikimedia services, with the rules of good use.
 *
 * @package Otherguise
 */

namespace Otherguise\Index\Wikidata;

defined( 'ABSPATH' ) || exit;

/**
 * The only door to the Wikimedia services (slice 604). It carries the rules of good use:
 *
 * - **a User-Agent that identifies the client and gives a way to contact its operator** (the option `index_wikidata_contact`, else the address
 *   of the site); without one **nothing is sent**;
 * - **one request at a time**, at least `MIN_INTERVAL` seconds apart (the time of the last request is kept, so that two processes agree);
 * - GET only, JSON, a timeout, the certificate checked;
 * - a refusal that says "wait" (429 or 503 with or without `Retry-After`, the `maxlag` and `ratelimited` errors of the API, an answer that is not
 *   JSON, which is what a rate limit sometimes looks like) is **`retry`**, with the number of seconds to wait; any other failure is `error`.
 *
 * It never retries by itself: the caller decides (and the job pauses itself after three failures in a row).
 */
final class Client {
	public const MIN_INTERVAL = 1.0;
	public const TIMEOUT      = 20;
	public const DEFAULT_WAIT = 60;

	/**
	 * Makes the request: `fn( string $url, array $args ): array|WP_Error`, as `wp_remote_get`.
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Returns the contact: `fn(): string`.
	 *
	 * @var callable
	 */
	private $contact;

	/**
	 * The version of the plugin.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Returns the time in seconds, with decimals.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Waits some seconds (a float).
	 *
	 * @var callable
	 */
	private $sleep;

	/**
	 * The options, for the time of the last request.
	 *
	 * @var Options
	 */
	private $options;

	/**
	 * Builds the client.
	 *
	 * @param Options       $options The options.
	 * @param callable|null $http    Default `wp_remote_get`.
	 * @param callable|null $contact Default the option `index_wikidata_contact`, else the address of the site.
	 * @param string        $version Version of the plugin.
	 * @param callable|null $now     Default `microtime( true )`.
	 * @param callable|null $sleep   Default `usleep`.
	 */
	public function __construct( Options $options, $http = null, $contact = null, $version = '0', $now = null, $sleep = null ) {
		$this->options = $options;
		$this->http    = $http ?? 'wp_remote_get';
		$this->contact = $contact ?? static function () use ( $options ) {
			$given = trim( (string) $options->get( 'index_wikidata_contact', '' ) );

			return '' !== $given ? $given : (string) home_url( '/' );
		};
		$this->version = (string) $version;
		$this->now     = $now ?? 'microtime';
		$this->sleep   = $sleep ?? static function ( $seconds ) {
			usleep( (int) round( $seconds * 1000000 ) );
		};
	}

	/**
	 * The User-Agent: `Otherguise-Index/<version> (<contact>)`, or an empty string when there is no contact.
	 *
	 * @return string
	 */
	public function user_agent() {
		$contact = trim( (string) ( $this->contact )() );

		return '' === $contact ? '' : 'Otherguise-Index/' . $this->version . ' (' . $contact . ')';
	}

	/**
	 * Asks for an address.
	 *
	 * @param string               $url   The address, without a query string.
	 * @param array<string, mixed> $query The parameters.
	 * @return array{ok:bool,class:string,status:int,json:?array,retry_after:int,error:string}
	 */
	public function get( $url, array $query = array() ) {
		$agent = $this->user_agent();

		if ( '' === $agent ) {
			return $this->answer( false, 'fatal', 0, null, 0, 'No contact for the User-Agent: give one with the option index_wikidata_contact.' );
		}

		$this->wait();

		$response = ( $this->http )(
			add_query_arg( $query, $url ),
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 2,
				'headers'     => array(
					'User-Agent' => $agent,
					'Accept'     => 'application/json',
				),
			)
		);

		$this->options->set( 'index_wikidata_last_request', (string) ( $this->now )( true ) );

		if ( is_wp_error( $response ) ) {
			return $this->answer( false, 'error', 0, null, 0, 'The request failed: ' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$wait   = $this->retry_after( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );

		if ( 429 === $status || 503 === $status ) {
			return $this->answer( false, 'retry', $status, null, $wait, 'The service asks to wait (status ' . $status . ').' );
		}

		if ( 200 !== $status ) {
			return $this->answer( false, 'error', $status, null, 0, 'The service answered with the status ' . $status . '.' );
		}

		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $json ) ) {
			// A rate limit sometimes comes as a plain text with the status 200.
			return $this->answer( false, 'retry', 200, null, $wait, 'The answer is not JSON (probably a rate limit).' );
		}

		$code = is_array( $json['error'] ?? null ) ? (string) ( $json['error']['code'] ?? '' ) : '';

		if ( in_array( $code, array( 'maxlag', 'ratelimited' ), true ) ) {
			return $this->answer( false, 'retry', 200, $json, $wait, 'The service is busy (' . $code . ').' );
		}

		if ( '' !== $code ) {
			return $this->answer( false, 'error', 200, $json, 0, 'The service refused the request (' . $code . ').' );
		}

		return $this->answer( true, 'ok', 200, $json, 0, '' );
	}

	/**
	 * Waits until the interval since the last request has passed.
	 *
	 * @return void
	 */
	private function wait() {
		$last = (float) $this->options->get( 'index_wikidata_last_request', 0 );
		$left = $last + self::MIN_INTERVAL - (float) ( $this->now )( true );

		if ( $left > 0 && $left <= self::MIN_INTERVAL ) {
			( $this->sleep )( $left );
		}
	}

	/**
	 * The seconds to wait from a `Retry-After` header: a number of seconds or a date; a minute when there is none.
	 *
	 * @param string $header The header.
	 * @return int
	 */
	private function retry_after( $header ) {
		$header = trim( $header );

		if ( '' === $header ) {
			return self::DEFAULT_WAIT;
		}

		if ( ctype_digit( $header ) ) {
			return max( 1, min( 3600, (int) $header ) );
		}

		$date = strtotime( $header );

		return false === $date ? self::DEFAULT_WAIT : max( 1, min( 3600, $date - (int) ( $this->now )( true ) ) );
	}

	/**
	 * An answer.
	 *
	 * @param bool       $ok          Whether it worked.
	 * @param string     $class       `ok`, `retry`, `error` or `fatal`.
	 * @param int        $status      HTTP status.
	 * @param array|null $json        The JSON.
	 * @param int        $retry_after Seconds to wait.
	 * @param string     $error       The reason.
	 * @return array{ok:bool,class:string,status:int,json:?array,retry_after:int,error:string}
	 */
	private function answer( $ok, $class, $status, $json, $retry_after, $error ) {
		return array(
			'ok'          => $ok,
			'class'       => $class,
			'status'      => $status,
			'json'        => $json,
			'retry_after' => $retry_after,
			'error'       => $error,
		);
	}
}
