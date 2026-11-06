<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The page that does not exist.
 *
 * @package Otherguise
 */

namespace Otherguise\Wiki\Missing;

use Otherguise\Wiki\Address\Router;
use Otherguise\Wiki\NameException;
use Otherguise\Wiki\Names;
use Otherguise\Wiki\Page\PageStore;

defined( 'ABSPATH' ) || exit;

/**
 * What the 404 of a wiki address knows and shows (slice 504). When the address is `/<Space>:<Title>` for a registered space and no page has
 * that name, the answer stays a **404**, and this offers to create the page: the name asked, **the pages of the same title in the other
 * spaces** (the angles), **the pages whose name is the same but for an accent or the case** (the similar pages) and, **for a user who may
 * create a page**, a form with a button. Nobody else is offered anything that writes: a crawler that asks for thousands of addresses makes
 * no page.
 *
 * The logic is the module's, the look is the theme's: the markup has classes (`wiki-missing`, `wiki-angles`, `wiki-similar`) and no style.
 */
final class MissingPage {
	/**
	 * Action of the form, handled on `admin-post.php`.
	 */
	public const ACTION = 'wiki_create';

	/**
	 * The names.
	 *
	 * @var Names
	 */
	private $names;

	/**
	 * Returns a query variable of the main query.
	 *
	 * @var callable
	 */
	private $query_var;

	/**
	 * Tells whether the main query is a 404.
	 *
	 * @var callable
	 */
	private $is_404;

	/**
	 * Tells whether the current user may create a page in a space.
	 *
	 * @var callable
	 */
	private $may_create;

	/**
	 * Tells whether the current user may see a page.
	 *
	 * @var callable
	 */
	private $visible;

	/**
	 * Returns the address of a page.
	 *
	 * @var callable
	 */
	private $permalink;

	/**
	 * Returns a nonce for an action.
	 *
	 * @var callable
	 */
	private $nonce;

	/**
	 * Returns the address of `admin-post.php`.
	 *
	 * @var callable
	 */
	private $admin_post;

	/**
	 * Builds the service.
	 *
	 * @param Names         $names      The names.
	 * @param callable|null $query_var  Receives a name; default `get_query_var`.
	 * @param callable|null $is_404     Default `is_404`.
	 * @param callable|null $may_create Receives a space; default: the capability of the type, through the filter `wiki_may_create`.
	 * @param callable|null $visible    Receives a page id; default: published, or the user may read it.
	 * @param callable|null $permalink  Receives a page id; default `get_permalink`.
	 * @param callable|null $nonce      Receives an action; default `wp_create_nonce`.
	 * @param callable|null $admin_post Default: `admin-post.php`.
	 */
	public function __construct( Names $names, $query_var = null, $is_404 = null, $may_create = null, $visible = null, $permalink = null, $nonce = null, $admin_post = null ) {
		$this->names      = $names;
		$this->query_var  = $query_var ?? 'get_query_var';
		$this->is_404     = $is_404 ?? 'is_404';
		$this->may_create = $may_create ?? array( $this, 'default_may_create' );
		$this->visible    = $visible ?? static function ( $id ) {
			return 'publish' === get_post_status( (int) $id ) || current_user_can( 'read_post', (int) $id );
		};
		$this->permalink  = $permalink ?? 'get_permalink';
		$this->nonce      = $nonce ?? 'wp_create_nonce';
		$this->admin_post = $admin_post ?? static function () {
			return admin_url( 'admin-post.php' );
		};
	}

	/**
	 * What was asked, when the request is a 404 of a wiki address (slice 503 keeps the space and the title).
	 *
	 * @return array{space: string, title: string}|null
	 */
	public function asked() {
		if ( ! (bool) ( $this->is_404 )() ) {
			return null;
		}

		$space = ( $this->query_var )( Router::SPACE );
		$title = ( $this->query_var )( Router::TITLE );

		return is_string( $space ) && '' !== $space && is_string( $title ) && '' !== $title ? array(
			'space' => $space,
			'title' => $title,
		) : null;
	}

	/**
	 * The nonce action of a creation: tied to the pair, so that a form is good for one name only.
	 *
	 * @param string $space The space.
	 * @param string $title The title of the name.
	 * @return string
	 */
	public static function nonce_action( $space, $title ) {
		return self::ACTION . ':' . $space . ':' . $title;
	}

	/**
	 * What the page shows, as data.
	 *
	 * @param array{space: string, title: string} $asked What was asked.
	 * @return array{name: string, space: string, title: string, refusal: string, may_create: bool, angles: array, similar: array}
	 */
	public function view( array $asked ) {
		$view = array(
			'name'       => $asked['space'] . ':' . $asked['title'],
			'space'      => $asked['space'],
			'title'      => $asked['title'],
			'refusal'    => '',
			'may_create' => false,
			'angles'     => array(),
			'similar'    => array(),
		);

		try {
			$parts = $this->names->qualify( $asked['space'], $asked['title'] );
		} catch ( NameException $refusal ) {
			$view['refusal'] = $refusal->getMessage();

			return $view;
		}

		$view['name']       = $parts['qualified'];
		$view['space']      = $parts['space'];
		$view['title']      = $parts['title'];
		$view['may_create'] = $this->may_create( $parts['space'] );
		$view['angles']     = $this->links( $this->names->elsewhere( $parts['title'], $parts['space'] ) );
		$view['similar']    = $this->links( $this->names->similar( $parts['space'], $parts['title'] ) );

		return $view;
	}

	/**
	 * The markup of the offer: nothing when the request is not the 404 of a wiki address.
	 *
	 * @param array{space: string, title: string}|null $asked What was asked; default: the request.
	 * @return string
	 */
	public function render( $asked = null ) {
		$asked = $asked ?? $this->asked();

		if ( null === $asked ) {
			return '';
		}

		$view = $this->view( $asked );
		$html = '<div class="wiki-missing"><p class="wiki-missing__message">' . esc_html__( 'This page does not exist:', 'otherguise' ) . ' <strong class="wiki-missing__name">' . esc_html( $view['name'] ) . '</strong></p>';

		if ( '' !== $view['refusal'] ) {
			return $html . '<p class="wiki-missing__refusal">' . esc_html( $view['refusal'] ) . '</p></div>';
		}

		if ( $view['may_create'] ) {
			$html .= '<form class="wiki-missing__create" method="post" action="' . esc_url( (string) ( $this->admin_post )() ) . '">'
				. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />'
				. '<input type="hidden" name="wiki_space" value="' . esc_attr( $view['space'] ) . '" />'
				. '<input type="hidden" name="wiki_title" value="' . esc_attr( $view['title'] ) . '" />'
				. '<input type="hidden" name="_wpnonce" value="' . esc_attr( (string) ( $this->nonce )( self::nonce_action( $view['space'], $view['title'] ) ) ) . '" />'
				. '<button type="submit" class="wp-element-button wiki-missing__button">' . esc_html__( 'Create this page', 'otherguise' ) . '</button></form>';
		}

		$html .= $this->list_html( 'wiki-angles', __( 'The same title in other spaces:', 'otherguise' ), $view['angles'] );
		$html .= $this->list_html( 'wiki-similar', __( 'Pages with a similar name:', 'otherguise' ), $view['similar'] );

		return $html . '</div>';
	}

	/**
	 * Tells whether the current user may create a page in a space.
	 *
	 * @param string $space The space.
	 * @return bool
	 */
	public function may_create( $space ) {
		return (bool) ( $this->may_create )( $space );
	}

	/**
	 * The HTML that the offer may hold, for `wp_kses()`: the markup of `render()` and nothing else.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html() {
		return array(
			'div'    => array( 'class' => true ),
			'p'      => array( 'class' => true ),
			'strong' => array( 'class' => true ),
			'ul'     => array(),
			'li'     => array(),
			'a'      => array( 'href' => true ),
			'form'   => array(
				'class'  => true,
				'method' => true,
				'action' => true,
			),
			'input'  => array(
				'type'  => true,
				'name'  => true,
				'value' => true,
			),
			'button' => array(
				'type'  => true,
				'class' => true,
			),
		);
	}

	/**
	 * Tells whether the current user may create a page in a space: the capability of the type, and the filter `wiki_may_create` (the rights
	 * per space are an open question: one right for all the spaces to begin with).
	 *
	 * @param string $space The space.
	 * @return bool
	 */
	public function default_may_create( $space ) {
		$type    = get_post_type_object( PageStore::TYPE );
		$allowed = is_object( $type ) && isset( $type->cap->create_posts ) && current_user_can( $type->cap->create_posts );

		return (bool) apply_filters( 'wiki_may_create', $allowed, $space, get_current_user_id() );
	}

	/**
	 * Keeps the pages that the user may see, with their address.
	 *
	 * @param array<int, array{post: int, name: string}> $found The pages.
	 * @return array<int, array{name: string, url: string}>
	 */
	private function links( array $found ) {
		$links = array();

		foreach ( $found as $page ) {
			if ( (bool) ( $this->visible )( $page['post'] ) ) {
				$links[] = array(
					'name' => $page['name'],
					'url'  => (string) ( $this->permalink )( $page['post'] ),
				);
			}
		}

		return $links;
	}

	/**
	 * The markup of a list of links, or nothing.
	 *
	 * @param string                                       $class_name The CSS class.
	 * @param string                                       $heading    The sentence before the list.
	 * @param array<int, array{name: string, url: string}> $links      The links.
	 * @return string
	 */
	private function list_html( $class_name, $heading, array $links ) {
		if ( array() === $links ) {
			return '';
		}

		$html = '<div class="' . esc_attr( $class_name ) . '"><p>' . esc_html( $heading ) . '</p><ul>';

		foreach ( $links as $link ) {
			$html .= '<li><a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['name'] ) . '</a></li>';
		}

		return $html . '</ul></div>';
	}
}
