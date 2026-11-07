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

use Otherguise\Wiki\NameException;
use Otherguise\Wiki\Tree\Keys;
use Otherguise\Wiki\Tree\Nodes;
use Otherguise\Wiki\Tree\Router;
use Otherguise\Wiki\Tree\Title;

defined( 'ABSPATH' ) || exit;

/**
 * What the 404 of a path of the tree knows and shows (slice 504, hierarchical model). The router (slice 503) knows where the path stopped:
 * the deepest page that exists and the titles that remain. The answer stays a **404**, and this shows:
 *
 * - the **approaching paths**: the same path read with the folded keys (an accent or a case that differs, at any level) and the pages that
 *   have the same last title elsewhere in the tree (the angles);
 * - **for a user who may create a page**, the creation: one title remains, the page is made below the deepest page; several remain, the
 *   whole chain is listed and **an explicit confirmation is asked**. Nobody else is offered anything that writes (a crawler asking for
 *   thousands of addresses makes no page). A chain never carries rules of its own.
 *
 * The logic is the module's, the look is the theme's: the markup has classes (`wiki-missing`, `wiki-angles`, `wiki-similar`) and no style.
 */
final class MissingPage {
	/**
	 * Action of the form, handled on `admin-post.php`.
	 */
	public const ACTION = 'wiki_create';

	/**
	 * The most titles that one confirmation creates.
	 */
	public const MAX_CHAIN = 10;

	/**
	 * The most approaching paths that are shown.
	 */
	public const MAX_APPROACHING = 10;

	/**
	 * The index of the tree.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * The router, which knows where the path stopped.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Tells whether the main query is a 404.
	 *
	 * @var callable
	 */
	private $is_404;

	/**
	 * Tells whether the current user may create a page under a parent.
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
	 * @param Keys          $keys       The index of the tree.
	 * @param Router        $router     The router.
	 * @param callable|null $is_404     Default `is_404`.
	 * @param callable|null $may_create Receives a parent id; default: the capability of the type `page`, through the filter `wiki_may_create`.
	 * @param callable|null $visible    Receives a page id; default: published, or the user may read it.
	 * @param callable|null $nonce      Receives an action; default `wp_create_nonce`.
	 * @param callable|null $admin_post Default: `admin-post.php`.
	 */
	public function __construct( Keys $keys, Router $router, $is_404 = null, $may_create = null, $visible = null, $nonce = null, $admin_post = null ) {
		$this->keys       = $keys;
		$this->router     = $router;
		$this->is_404     = $is_404 ?? 'is_404';
		$this->may_create = $may_create ?? array( $this, 'default_may_create' );
		$this->visible    = $visible ?? static function ( $id ) {
			return 'publish' === get_post_status( (int) $id ) || current_user_can( 'read_post', (int) $id );
		};
		$this->nonce      = $nonce ?? 'wp_create_nonce';
		$this->admin_post = $admin_post ?? static function () {
			return admin_url( 'admin-post.php' );
		};
	}

	/**
	 * Where the path stopped, when the request is the 404 of a path of the tree.
	 *
	 * @return array{deepest: int, rest: string[]}|null
	 */
	public function asked() {
		return (bool) ( $this->is_404 )() ? $this->router->missing() : null;
	}

	/**
	 * The nonce action of a creation: tied to the parent and to the titles, so that a form is good for one chain only.
	 *
	 * @param int      $parent The parent (0 for the first level).
	 * @param string[] $titles The titles to create, from the parent down.
	 * @return string
	 */
	public static function nonce_action( $parent, array $titles ) {
		return self::ACTION . ':' . (int) $parent . ':' . implode( '/', $titles );
	}

	/**
	 * What the page shows, as data.
	 *
	 * @param array{deepest: int, rest: string[]} $asked Where the path stopped.
	 * @return array{path: string, parent: int, titles: string[], refusal: string, may_create: bool, chain: bool, approaching: array, angles: array}
	 */
	public function view( array $asked ) {
		$parent = (int) $asked['deepest'];
		$base   = $parent > 0 ? (array) $this->keys->path_of( $parent ) : array();
		$view   = array(
			'path'        => '/' . implode( '/', array_merge( $base, $asked['rest'] ) ),
			'parent'      => $parent,
			'titles'      => array(),
			'refusal'     => '',
			'may_create'  => false,
			'chain'       => count( $asked['rest'] ) > 1,
			'approaching' => array(),
			'angles'      => array(),
		);

		try {
			$titles = array_map( array( Title::class, 'title' ), $asked['rest'] );
		} catch ( NameException $refusal ) {
			$view['refusal'] = $refusal->getMessage();

			return $view;
		}

		if ( count( $titles ) > self::MAX_CHAIN ) {
			$view['refusal'] = __( 'This address is too deep to be created in one go.', 'otherguise' );

			return $view;
		}

		$refusal = $this->keys->check( 0, $parent, $titles[0] );

		if ( null !== $refusal && NameException::TAKEN !== $refusal->error_code() ) {
			$view['refusal'] = $refusal->getMessage();

			return $view;
		}

		$view['titles']      = $titles;
		$view['may_create']  = $this->may_create( $parent );
		$view['approaching'] = $this->links( $this->approaching( $parent, $titles ) );
		$view['angles']      = $this->links( $this->angles( (string) end( $titles ) ) );

		return $view;
	}

	/**
	 * The markup of the offer: nothing when the request is not the 404 of a path of the tree.
	 *
	 * @param array{deepest: int, rest: string[]}|null $asked Where the path stopped; default: the request.
	 * @return string
	 */
	public function render( $asked = null ) {
		$asked = $asked ?? $this->asked();

		if ( null === $asked ) {
			return '';
		}

		$view = $this->view( $asked );
		$html = '<div class="wiki-missing"><p class="wiki-missing__message">' . esc_html__( 'This page does not exist:', 'otherguise' ) . ' <strong class="wiki-missing__name">' . esc_html( $view['path'] ) . '</strong></p>';

		if ( '' !== $view['refusal'] ) {
			return $html . '<p class="wiki-missing__refusal">' . esc_html( $view['refusal'] ) . '</p></div>';
		}

		$html .= $this->list_html( 'wiki-similar', __( 'Did you mean:', 'otherguise' ), $view['approaching'] );
		$html .= $this->list_html( 'wiki-angles', __( 'The same title elsewhere:', 'otherguise' ), $view['angles'] );

		return $html . $this->form_html( $view ) . '</div>';
	}

	/**
	 * Tells whether the current user may create a page under a parent.
	 *
	 * @param int $parent The parent (0 for the first level).
	 * @return bool
	 */
	public function may_create( $parent ) {
		return (bool) ( $this->may_create )( (int) $parent );
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
			'ul'     => array( 'class' => true ),
			'ol'     => array( 'class' => true ),
			'li'     => array(),
			'label'  => array( 'class' => true ),
			'a'      => array( 'href' => true ),
			'form'   => array(
				'class'  => true,
				'method' => true,
				'action' => true,
			),
			'input'  => array(
				'type'     => true,
				'name'     => true,
				'value'    => true,
				'required' => true,
			),
			'button' => array(
				'type'  => true,
				'class' => true,
			),
		);
	}

	/**
	 * Tells whether the current user may create a page under a parent: the capability of the type `page` (and the right to edit the parent),
	 * and the filter `wiki_may_create` (the rights per branch are an open question).
	 *
	 * @param int $parent The parent.
	 * @return bool
	 */
	public function default_may_create( $parent ) {
		$type    = get_post_type_object( Nodes::TYPE );
		$allowed = is_object( $type ) && isset( $type->cap->create_posts ) && current_user_can( $type->cap->create_posts )
			&& ( $parent <= 0 || current_user_can( 'edit_post', $parent ) );

		return (bool) apply_filters( 'wiki_may_create', $allowed, $parent, get_current_user_id() );
	}

	/**
	 * The paths that approach the one asked: every level read with the folded keys, so that an accent or a case that differs is found at any
	 * level. Bounded.
	 *
	 * @param int      $parent The page the path stopped at.
	 * @param string[] $titles The titles that were not found.
	 * @return int[] The pages that end a path, by id.
	 */
	private function approaching( $parent, array $titles ) {
		$found = array();

		$this->walk( $parent, $titles, $found );

		return array_slice( $found, 0, self::MAX_APPROACHING );
	}

	/**
	 * Walks the titles from a parent, the exact page if there is one and the similar ones otherwise.
	 *
	 * @param int      $parent The parent.
	 * @param string[] $titles The titles left.
	 * @param int[]    $found  The pages that end a path (changed).
	 * @param int      $depth  Guards the recursion.
	 * @return void
	 */
	private function walk( $parent, array $titles, array &$found, $depth = 0 ) {
		if ( array() === $titles || $depth >= self::MAX_CHAIN || count( $found ) >= self::MAX_APPROACHING ) {
			return;
		}

		$title = array_shift( $titles );
		$exact = $this->keys->resolve( $parent, $title );
		$pages = null !== $exact ? array( $exact['post'] ) : array_column( $this->keys->similar( $parent, $title ), 'post' );

		foreach ( $pages as $page ) {
			if ( array() === $titles ) {
				if ( null === $exact ) {
					$found[ $page ] = $page;
				}
			} else {
				$this->walk( (int) $page, $titles, $found, $depth + 1 );
			}
		}
	}

	/**
	 * The pages that have the same title anywhere in the tree.
	 *
	 * @param string $title The last title asked.
	 * @return int[] Pages.
	 */
	private function angles( $title ) {
		return array_column( $this->keys->elsewhere( $title ), 'post' );
	}

	/**
	 * Keeps the pages that the user may see, with their address.
	 *
	 * @param int[] $pages Page ids.
	 * @return array<int, array{name: string, url: string}>
	 */
	private function links( array $pages ) {
		$links = array();

		foreach ( $pages as $page ) {
			$titles = $this->keys->path_of( (int) $page );

			if ( null !== $titles && (bool) ( $this->visible )( (int) $page ) ) {
				$links[] = array(
					'name' => '/' . implode( '/', $titles ),
					'url'  => $this->router->canonical( $titles ),
				);
			}
		}

		return $links;
	}

	/**
	 * The form of the creation, or nothing. A chain lists every page that would be made and asks for a confirmation.
	 *
	 * @param array<string, mixed> $view The view.
	 * @return string
	 */
	private function form_html( array $view ) {
		if ( ! $view['may_create'] ) {
			return '';
		}

		$parent = (int) $view['parent'];
		$titles = $view['titles'];
		$html   = '<form class="wiki-missing__create" method="post" action="' . esc_url( (string) ( $this->admin_post )() ) . '">';

		if ( $view['chain'] ) {
			$html .= '<p>' . esc_html__( 'These pages would be created, as drafts:', 'otherguise' ) . '</p><ol class="wiki-missing__chain">';

			foreach ( $titles as $title ) {
				$html .= '<li>' . esc_html( $title ) . '</li>';
			}

			$html .= '</ol><label class="wiki-missing__confirm"><input type="checkbox" name="wiki_confirm" value="1" required /> ' . esc_html__( 'I confirm that these pages are created', 'otherguise' ) . '</label>';
		}

		return $html
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />'
			. '<input type="hidden" name="wiki_parent" value="' . esc_attr( (string) $parent ) . '" />'
			. '<input type="hidden" name="wiki_titles" value="' . esc_attr( implode( '/', $titles ) ) . '" />'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( (string) ( $this->nonce )( self::nonce_action( $parent, $titles ) ) ) . '" />'
			. '<button type="submit" class="wp-element-button wiki-missing__button">' . esc_html( $view['chain'] ? __( 'Create these pages', 'otherguise' ) : __( 'Create this page', 'otherguise' ) ) . '</button></form>';
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
