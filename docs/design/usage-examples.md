<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Usage examples

How the statements look and how the API of [slice 103](../slices/103-triples-statements.md) is used to create them and to keep them alive: associating a print template with a web template, a photo with a post, selecting the photos of a post for a mode, assembling a book.

**Status.** The registry and the store exist (slices 100 to 102). The service `Statements` used below is **planned** (slice 103): the calls are the planned API. The predicates `modes/has-variant`, `modes/mode`, `media/illustrated-by`, `books/contains` and `books/pages` are **proposals**, registered by the Modes module, the Media Helper integration and the Books module, which are not written yet. `triples/position` is built in.

Notation: a statement is written `id: (subject, predicate, object)`. `$statements` is the service (`Module::statements()`), `Ref` stands for `EntityRef::parse()`. The statement ids are illustrative.

## 0. What the modules register

```php
add_action( 'triples_register_entity_types', function ( $types ) {
    // Modes: a template (id "theme//slug") and a mode (a declared mode slug).
    $types->register( new EntityType( 'template', 'Template', $is_template_id, null, $template_exists ) );
    $types->register( new EntityType( 'mode', 'Mode', $is_declared_mode, null, $is_declared_mode ) );
} );

add_action( 'triples_register_predicates', function ( $predicates ) {
    $predicates->register( PredicateDefinition::from_array( array(
        'slug' => 'modes/has-variant', 'label' => 'Has variant', 'inverse_label' => 'Variant of',
        'subject_types' => array( 'template' ), 'object_types' => array( 'template' ),
        'qualified_by'  => array( 'modes/mode' ),
    ) ) );

    // A mode statement is about another statement; the position qualifier is built in and qualifies anything.
    $predicates->register( PredicateDefinition::from_array( array(
        'slug' => 'modes/mode', 'label' => 'In mode',
        'subject_types' => array( 'statement' ), 'object_types' => array( 'mode' ),
    ) ) );

    // Media Helper integration.
    $predicates->register( PredicateDefinition::from_array( array(
        'slug' => 'media/illustrated-by', 'label' => 'Illustrated by', 'inverse_label' => 'Illustrates',
        'subject_types' => array( 'post' ), 'object_types' => array( 'attachment' ),
        'qualified_by'  => array( 'modes/mode' ),
    ) ) );

    // Books: a book is a post of a type "book"; "contains" is ordered and may declare a page count.
    $predicates->register( PredicateDefinition::from_array( array(
        'slug' => 'books/contains', 'label' => 'Contains', 'inverse_label' => 'Part of',
        'subject_types' => array( 'post' ), 'object_types' => array( 'post' ),
        'qualified_by'  => array( 'modes/mode', 'books/pages' ),
    ) ) );
    $predicates->register( PredicateDefinition::from_array( array(
        'slug' => 'books/pages', 'label' => 'Pages',
        'subject_types' => array( 'statement' ), 'object_types' => array( 'integer' ), 'max_objects_per_subject' => 1,
    ) ) );
} );
```

Reading rule used by `listing()`: **no `modes/mode` statement about a statement means it applies to every mode**; with one or more, it applies to those modes only.

## 1. Associate a print template with a web template

The print template `single-print` (created in the site editor) is the print version of `single`.

```php
$web   = Ref( 'template:twentytwentyfive//single' );
$print = Ref( 'template:twentytwentyfive//single-print' );

$variant = $statements->create( $web, 'modes/has-variant', $print, array(
    array( 'modes/mode', Ref( 'mode:print' ) ),
) );
```

```
1: (template:twentytwentyfive//single, modes/has-variant, template:twentytwentyfive//single-print)
2: (statement:1, modes/mode, mode:print)
```

**Resolve the template of a page in a mode** (the Modes module does this on each request; `listing()` returns the variants that apply to the mode; the first one wins; none means "use the normal template"):

```php
$variants = $statements->listing( $web, 'modes/has-variant', array( 'scope' => array( 'modes/mode', Ref( 'mode:print' ) ) ) );
$template = array() === $variants ? $web : $variants[0]->object();
```

**The same print template for another web template** (a template may be the variant of several):

```php
$statements->create( Ref( 'template:twentytwentyfive//page' ), 'modes/has-variant', $print, array( array( 'modes/mode', Ref( 'mode:print' ) ) ) );
```
```
3: (template:twentytwentyfive//page, modes/has-variant, template:twentytwentyfive//single-print)
4: (statement:3, modes/mode, mode:print)
```

**The same variant also serves the `book` mode**, and **a template part** works the same way:

```php
$statements->create( $variant->as_entity(), 'modes/mode', Ref( 'mode:book' ) );   // 5: (statement:1, modes/mode, mode:book)
$statements->create( Ref( 'template:twentytwentyfive//header' ), 'modes/has-variant', Ref( 'template:twentytwentyfive//header-print' ),
    array( array( 'modes/mode', Ref( 'mode:print' ) ) ) );
```

**Change or remove**:

```php
// Use another print template for single: delete the variant (its mode statements go with it), create the new one.
$statements->delete( $variant->id() );                       // deletes statements 1, 2 and 5
$statements->create( $web, 'modes/has-variant', Ref( 'template:twentytwentyfive//single-print-v2' ), array( array( 'modes/mode', Ref( 'mode:print' ) ) ) );

// The template is no longer a variant for print only: delete the mode statement.
$statements->delete( $statements->find_by_triple( $variant->as_entity(), 'modes/mode', Ref( 'mode:print' ) )->id() );
```

All the web templates that use a given print template: `$statements->subjects_of( $print, 'modes/has-variant' )`.

## 2. Associate a photo with a post

```php
$photo = $statements->create( Ref( 'post:12' ), 'media/illustrated-by', Ref( 'attachment:88' ) );
```
```
10: (post:12, media/illustrated-by, attachment:88)
```

No mode statement: the photo is shown **in every mode**. The post must exist, the attachment must be a media item, and the photo may belong to any number of posts: `ensure()` is the idempotent form (a second call returns statement 10 instead of failing).

The photos of a post: `$statements->objects_of( Ref( 'post:12' ), 'media/illustrated-by' )`. The posts that use a photo: `$statements->subjects_of( Ref( 'attachment:88' ), 'media/illustrated-by' )`.

**Only in some modes**, in the same call:

```php
$statements->create( Ref( 'post:12' ), 'media/illustrated-by', Ref( 'attachment:91' ), array(
    array( 'modes/mode', Ref( 'mode:web' ) ),
    array( 'modes/mode', Ref( 'mode:print' ) ),
) );
```
```
11: (post:12, media/illustrated-by, attachment:91)
12: (statement:11, modes/mode, mode:web)
13: (statement:11, modes/mode, mode:print)
```

Everything is written in one transaction; if any part is refused (unknown mode, missing attachment) nothing is stored.

## 3. Select the photos of a post that concern the print mode

State: photo A (attachment:88) has no mode statement; B (attachment:90) is `web` only; C (attachment:91) is `web` and `print`.

```php
$photos = $statements->listing(
    Ref( 'post:12' ), 'media/illustrated-by',
    array(
        'scope'         => array( 'modes/mode', Ref( 'mode:print' ) ),
        'natural_order' => function ( Statement $a, Statement $b ) {
            return photo_time( $a->object() ) <=> photo_time( $b->object() );   // date and time of the photo
        },
    )
);
// A and C, in order of date and time. B is not shown in print.
foreach ( $photos as $statement ) {
    $attachment_id = (int) $statement->object()->key();
}
```

With `'scope' => array( 'modes/mode', Ref( 'mode:web' ) )` the result is A, B, C. Without `natural_order` the order is the order of creation.

## 4. Keep the photos alive: remove, restrict, add, reorder

**Remove a photo from the post** (every mode): delete its statement; the mode statements and positions about it go with it.

```php
$statements->delete( $statements->find_by_triple( Ref( 'post:12' ), 'media/illustrated-by', Ref( 'attachment:91' ) )->id() );   // deletes 11, 12, 13
```

**Remove a photo from the print mode only.** Two cases, because "no mode statement" means "every mode":

```php
$photo = $statements->find_by_triple( Ref( 'post:12' ), 'media/illustrated-by', Ref( 'attachment:88' ) );
$modes = $statements->match( $photo->as_entity(), 'modes/mode', null );

if ( array() === $modes ) {
    // It applies to every mode today (photo A): make the scope explicit, without print.
    foreach ( $declared_modes as $mode ) {                       // the declared modes other than print
        $statements->create( $photo->as_entity(), 'modes/mode', Ref( 'mode:' . $mode ) );
    }
} else {
    // Explicit scope (photo C): delete the print statement, and the rank that was set for print only.
    $statements->delete( $statements->find_by_triple( $photo->as_entity(), 'modes/mode', Ref( 'mode:print' ) )->id() );

    if ( array() === $statements->match( $photo->as_entity(), 'modes/mode', null ) ) {
        // That was the only mode: the photo would now apply to every mode. It is nowhere any more: delete it.
        $statements->delete( $photo->id() );
    }
}
```

**Add a photo** to every mode, or to print only:

```php
$statements->create( Ref( 'post:12' ), 'media/illustrated-by', Ref( 'attachment:92' ) );
$statements->create( Ref( 'post:12' ), 'media/illustrated-by', Ref( 'attachment:93' ), array( array( 'modes/mode', Ref( 'mode:print' ) ) ) );
```

**Put a photo first** (a "pin"; the default order is the date and time of the photos). A rank for every mode, then a different rank for print only:

```php
$statements->replace( $photo->as_entity(), 'triples/position', 5 );            // 20: (statement:10, triples/position, 5)
$print_mode = $statements->find_by_triple( $photo->as_entity(), 'modes/mode', Ref( 'mode:print' ) );
$statements->replace( $print_mode->as_entity(), 'triples/position', 1 );       // 21: (statement:13, triples/position, 1)
```

`replace()` deletes the previous position and creates the new one; to unpin, delete the position statement. `listing()` applies the reading rule of slice 103: the order of all the photos with the pins of every mode, then the filter by mode, then the ranks that are specific to the mode.

## 5. Assemble a book

A book is a post (of a type `book`), `post:200`. It contains posts; a part (a month, for instance) is itself a post that contains the days.

```php
$book = Ref( 'post:200' );
$part = Ref( 'post:300' );                                   // "Premier mois"

$statements->ensure( $book, 'books/contains', $part );
foreach ( $day_post_ids as $id ) {                           // for example the posts of a period, read with a date query
    $statements->ensure( $part, 'books/contains', Ref( 'post:' . $id ) );
}
```
```
30: (post:200, books/contains, post:300)
31: (post:300, books/contains, post:12)
32: (post:300, books/contains, post:13)
...
```

**Read the book**, in order (here the natural order is the date of the post), parts first:

```php
$compare = function ( Statement $a, Statement $b ) { return post_time( $a->object() ) <=> post_time( $b->object() ); };
foreach ( $statements->listing( $book, 'books/contains', array( 'natural_order' => $compare ) ) as $part_statement ) {
    foreach ( $statements->listing( $part_statement->object(), 'books/contains', array( 'natural_order' => $compare ) ) as $day ) {
        // render $day->object() in the print mode; its photos: listing( ..., 'media/illustrated-by', scope print )
    }
}
```

**Declare that a post takes two pages** (for the page numbers and the table of contents), **put a post first, remove a post, reuse a post in another book**:

```php
$statements->replace( $statements->find_by_triple( $part, 'books/contains', Ref( 'post:13' ) )->as_entity(), 'books/pages', 2 );   // 40: (statement:32, books/pages, 2)
$statements->replace( $statements->find_by_triple( $part, 'books/contains', Ref( 'post:13' ) )->as_entity(), 'triples/position', 1 );
$statements->delete( $statements->find_by_triple( $part, 'books/contains', Ref( 'post:12' ) )->id() );
$statements->ensure( Ref( 'post:201' ), 'books/contains', Ref( 'post:13' ) );                                                        // another book
```

Which books contain a post: `$statements->subjects_of( Ref( 'post:13' ), 'books/contains' )`; what a book contains, flat: `$statements->objects_of( $book, 'books/contains' )`.

## What this exercise shows

Points to settle, noted for the Modes and Books slices:

1. **Excluding one mode makes the scope explicit.** "Everything except print" is written as the list of the other modes. A mode declared later is not included in such a scope. An explicit exclusion form was left out so far ("not planned"); to decide if this matters.
2. **Removing the last mode statement would widen the scope to every mode.** The Modes module must guard against it (delete the photo instead), as in the example; a low-level `delete()` does not know.
3. **"One variant per template and mode" is not a limit of the registry.** `max_objects_per_subject` counts all the variants of a template, whatever the mode. The Modes module has to check it before creating a variant.
4. **`Ref( ... )` is verbose.** A shorthand for the common entity types (`Ref::post( 12 )`) would make the code easier to read; cosmetic, to decide with the first consumer.
5. **The options of `listing()`** (`scope`, `natural_order`) are fixed by these examples and added to the plan of slice 103.
