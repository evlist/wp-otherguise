=== Otherguise ===
Contributors: evlist
Tags: templates, relations, books, print
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

The same content in another guise.

== Description ==

Otherguise lets a WordPress site present the same content in several forms: on
the web, in print, as a book.

It is made of three modules:

* Triples: relations between things (posts, media, terms, templates), with a registry of the kinds of relation.
* Modes: alternative templates selected by a mode, for example `?mode=print`.
* Books: books assembled from posts, with a table of contents and an index.

This is a work in progress: the current version only contains the structure of
the plugin and its module loader.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/otherguise` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the "Plugins" screen in WordPress.

== Changelog ==

= 0.0.1 =
* Structure of the plugin and module loader.
