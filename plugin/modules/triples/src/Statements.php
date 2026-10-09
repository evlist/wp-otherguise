<?php
/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Service that creates and reads statements.
 *
 * @package Otherguise
 */

namespace Otherguise\Triples;

use Otherguise\Triples\Entity\EntityRef;
use Otherguise\Triples\Service\EntityResolver;
use Otherguise\Triples\Service\EventQueue;
use Otherguise\Triples\Service\InvalidStatementException;
use Otherguise\Triples\Service\StatementEraser;
use Otherguise\Triples\Service\StatementListing;
use Otherguise\Triples\Service\StatementReader;
use Otherguise\Triples\Service\StatementValidator;
use Otherguise\Triples\Storage\Database;
use Otherguise\Triples\Storage\DuplicateStatementException;
use Otherguise\Triples\Storage\Statement;
use Otherguise\Triples\Storage\StatementStore;
use Otherguise\Triples\Storage\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * The API of the module: small calls that compose, obtained from `Module::statements()`.
 *
 * A subject, or an entity used as object, is what the caller already holds: a WordPress object, a stored `Statement` (a statement about
 * a statement) or an `EntityRef`. An object that is not an entity is a `Literal` or a PHP scalar read as a literal of the only datatype
 * the predicate accepts. Every call that stores something is checked (see `StatementValidator`) and throws `InvalidStatementException`.
 * See `docs/design/usage-examples.md`.
 */
final class Statements {
	/**
	 * Store.
	 *
	 * @var StatementStore
	 */
	private $store;

	/**
	 * Database, for the transactions.
	 *
	 * @var Database
	 */
	private $database;

	/**
	 * Validator.
	 *
	 * @var StatementValidator
	 */
	private $validator;

	/**
	 * Resolver.
	 *
	 * @var EntityResolver
	 */
	private $resolver;

	/**
	 * Reader.
	 *
	 * @var StatementReader
	 */
	private $reader;

	/**
	 * Listing.
	 *
	 * @var StatementListing
	 */
	private $listing;

	/**
	 * Eraser.
	 *
	 * @var StatementEraser
	 */
	private $eraser;

	/**
	 * Events.
	 *
	 * @var EventQueue
	 */
	private $events;

	/**
	 * Builds the service.
	 *
	 * @param StatementStore     $store     Store.
	 * @param Database           $database  Database.
	 * @param StatementValidator $validator Validator.
	 * @param EntityResolver     $resolver  Resolver.
	 * @param StatementReader    $reader    Reader.
	 * @param StatementListing   $listing   Listing.
	 * @param StatementEraser    $eraser    Eraser.
	 * @param EventQueue         $events    Events.
	 */
	public function __construct( StatementStore $store, Database $database, StatementValidator $validator, EntityResolver $resolver, StatementReader $reader, StatementListing $listing, StatementEraser $eraser, EventQueue $events ) {
		$this->store     = $store;
		$this->database  = $database;
		$this->validator = $validator;
		$this->resolver  = $resolver;
		$this->reader    = $reader;
		$this->listing   = $listing;
		$this->eraser    = $eraser;
		$this->events    = $events;
	}

	/**
	 * Returns the statement that holds a triple, creating it when it does not exist. Idempotent.
	 *
	 * @param mixed  $subject   Subject: an object, a `Statement` or an `EntityRef`.
	 * @param string $predicate Predicate slug.
	 * @param mixed  $target    Object: an entity as the subject, a `Literal` or a scalar.
	 * @return Statement
	 * @throws InvalidStatementException When a check fails.
	 */
	public function triple( $subject, $predicate, $target ) {
		$statement = $this->prepared( $subject, $predicate, $target );
		$existing  = $this->store->find_by_triple( $statement->subject(), $statement->predicate(), $statement->object() );

		if ( null !== $existing ) {
			return $existing;
		}

		return $this->insert( $statement, true );
	}

	/**
	 * Creates a statement; the strict form of `triple()`.
	 *
	 * @param mixed  $subject   Subject.
	 * @param string $predicate Predicate slug.
	 * @param mixed  $target    Object.
	 * @return Statement
	 * @throws InvalidStatementException   When a check fails.
	 * @throws DuplicateStatementException When the triple exists; the exception carries the existing statement.
	 */
	public function create( $subject, $predicate, $target ) {
		$statement = $this->prepared( $subject, $predicate, $target );
		$existing  = $this->store->find_by_triple( $statement->subject(), $statement->predicate(), $statement->object() );

		if ( null !== $existing ) {
			$duplicate = DuplicateStatementException::for_existing( $existing );

			throw $duplicate;
		}

		return $this->insert( $statement, false );
	}

	/**
	 * Runs the checks of a statement without writing anything. A triple that exists is reported like by `create()`.
	 *
	 * @param mixed  $subject   Subject.
	 * @param string $predicate Predicate slug.
	 * @param mixed  $target    Object.
	 * @return true
	 * @throws InvalidStatementException      When a check fails.
	 * @throws DuplicateStatementException When the triple exists.
	 */
	public function check( $subject, $predicate, $target ) {
		$statement = $this->prepared( $subject, $predicate, $target );
		$existing  = $this->store->find_by_triple( $statement->subject(), $statement->predicate(), $statement->object() );

		if ( null !== $existing ) {
			$duplicate = DuplicateStatementException::for_existing( $existing );

			throw $duplicate;
		}

		$this->validator->check_limits( $statement );

		return true;
	}

	/**
	 * Deletes the statement that holds a triple and the statements about it.
	 *
	 * @param mixed  $subject   Subject.
	 * @param string $predicate Predicate slug.
	 * @param mixed  $target    Object.
	 * @return int Number of statements deleted; 0 when there is none.
	 */
	public function remove( $subject, $predicate, $target ) {
		$existing = $this->reader->find_by_triple( $subject, $predicate, $target );

		return null === $existing ? 0 : $this->eraser->erase( array( $existing->id() ) );
	}

	/**
	 * Deletes a known statement and the statements about it.
	 *
	 * @param Statement|int $statement Statement or its id.
	 * @return int Number of statements deleted.
	 */
	public function delete( $statement ) {
		return $this->eraser->erase( array( $statement instanceof Statement ? (int) $statement->id() : (int) $statement ) );
	}

	/**
	 * Deletes what involves an entity that no longer exists, with the statements about it: the predicates declared `keep` stay.
	 * WordPress calls this for posts, media items, terms and users; the modules that register other entity types call it when those
	 * disappear.
	 *
	 * @param mixed $entity Entity: an `EntityRef` (`Ref::post( 12 )`), since the object itself is usually gone.
	 * @return int Number of statements deleted.
	 */
	public function forget( $entity ) {
		return $this->eraser->forget( $this->resolver->entity( $entity ) );
	}

	/**
	 * Makes a triple the only one of its subject and predicate: deletes the others, with what is said about them, and creates it.
	 * A statement that already holds the triple is kept with its qualifications. For single-valued predicates such as `triples/position`.
	 *
	 * @param mixed  $subject   Subject.
	 * @param string $predicate Predicate slug.
	 * @param mixed  $target    Object.
	 * @return Statement
	 * @throws InvalidStatementException When a check fails; nothing is changed then.
	 */
	public function replace( $subject, $predicate, $target ) {
		$wanted = $this->prepared( $subject, $predicate, $target );

		return $this->transaction(
			function () use ( $wanted ) {
				$kept = $this->store->find_by_triple( $wanted->subject(), $wanted->predicate(), $wanted->object() );

				foreach ( $this->reader->match( $wanted->subject(), $wanted->predicate() ) as $other ) {
					if ( null === $kept || $other->id() !== $kept->id() ) {
						$this->eraser->erase( array( $other->id() ) );
					}
				}

				return $this->triple( $wanted->subject(), $wanted->predicate(), $wanted->object() );
			}
		);
	}

	/**
	 * Runs several calls atomically: everything is stored or, when a call throws, nothing. Transactions nest; only the outermost commits.
	 *
	 * @param callable $work Work to do; its result is returned.
	 * @return mixed
	 * @throws \Throwable What the work throws, after the rollback.
	 */
	public function transaction( $work ) {
		return Transaction::run( $this->database, $work );
	}

	/**
	 * Reads a statement by id.
	 *
	 * @param int $id Statement id.
	 * @return Statement|null
	 */
	public function find( $id ) {
		return $this->reader->find( $id );
	}

	/**
	 * Reads the statement that holds a triple.
	 *
	 * @param mixed  $subject   Subject.
	 * @param string $predicate Predicate slug.
	 * @param mixed  $target    Object.
	 * @return Statement|null
	 */
	public function find_by_triple( $subject, $predicate, $target ) {
		return $this->reader->find_by_triple( $subject, $predicate, $target );
	}

	/**
	 * Reads the statements that fit a pattern, a part left null being a wildcard.
	 *
	 * @param mixed       $subject   Subject, or null.
	 * @param string|null $predicate Predicate slug, or null.
	 * @param mixed       $target    Object, or null.
	 * @return Statement[]
	 */
	public function match( $subject = null, $predicate = null, $target = null ) {
		return $this->reader->match( $subject, $predicate, $target );
	}

	/**
	 * Reads the statements of a subject.
	 *
	 * @param mixed       $subject   Subject.
	 * @param string|null $predicate Predicate slug, or null for all.
	 * @return Statement[]
	 */
	public function objects_of( $subject, $predicate = null ) {
		return $this->reader->match( $subject, $predicate );
	}

	/**
	 * Reads the statements that have an entity or a literal as object.
	 *
	 * @param mixed       $target    Object.
	 * @param string|null $predicate Predicate slug, or null for all.
	 * @return Statement[]
	 */
	public function subjects_of( $target, $predicate = null ) {
		return $this->reader->match( null, $predicate, $target );
	}

	/**
	 * Reads the statements about some statements, in one query.
	 *
	 * @param array<int, Statement|int> $statements Statements or ids.
	 * @return array<int, array<string, Statement[]>> By statement id, then by predicate.
	 */
	public function qualifications_of( array $statements ) {
		return $this->reader->qualifications_of( $statements );
	}

	/**
	 * Returns the ordered, scoped list of the statements of a subject and a predicate (see `StatementListing`).
	 *
	 * @param mixed                $subject   Subject.
	 * @param string               $predicate Predicate slug.
	 * @param array<string, mixed> $options   `scope` and `natural_order`.
	 * @return Statement[]
	 */
	public function listing( $subject, $predicate, array $options = array() ) {
		return $this->listing->listing( $subject, $predicate, $options );
	}

	/**
	 * Returns the WordPress object an entity stands for, or null when its type has no loader or it does not exist.
	 *
	 * @param EntityRef $entity Entity, for instance `$statement->object()`.
	 * @return mixed
	 */
	public function resolve( EntityRef $entity ) {
		return $this->resolver->resolve( $entity );
	}

	/**
	 * Runs the checks that need no limit and returns the statement as it would be stored.
	 *
	 * @param mixed  $subject   Subject.
	 * @param string $predicate Predicate slug.
	 * @param mixed  $target    Object.
	 * @return Statement
	 */
	private function prepared( $subject, $predicate, $target ) {
		$statement = $this->validator->normalize( $subject, $predicate, $target );

		$this->validator->check_references( $statement );

		return $statement;
	}

	/**
	 * Checks the limits and stores a statement.
	 *
	 * @param Statement $statement Normalized statement.
	 * @param bool      $idempotent Whether a duplicate stored meanwhile is returned instead of thrown.
	 * @return Statement
	 * @throws DuplicateStatementException When the triple exists and the call is strict.
	 */
	private function insert( Statement $statement, $idempotent ) {
		$this->validator->check_limits( $statement );

		try {
			$id = $this->store->insert( $statement );
		} catch ( DuplicateStatementException $duplicate ) {
			if ( $idempotent && null !== $duplicate->existing() ) {
				return $duplicate->existing();
			}

			throw $duplicate;
		}

		$created = $this->store->find( $id );

		$this->events->created( $created );

		return $created;
	}
}
