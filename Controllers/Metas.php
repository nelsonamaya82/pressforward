<?php
/**
 * Postmeta tools.
 *
 * @package PressForward
 */

namespace PressForward\Controllers;

use Intraxia\Jaxion\Contract\Core\HasActions;
use Intraxia\Jaxion\Contract\Core\HasFilters;
use PressForward\Interfaces\System;
use PressForward\Interfaces\SystemMeta;

/**
 * Functionality related to nominations.
 */
class Metas implements HasFilters, HasActions {
	/**
	 * SystemMeta object.
	 *
	 * @access public
	 * @var PressForward\Interfaces\SystemMeta
	 */
	public $meta_interface;

	/**
	 * System object.
	 *
	 * @access public
	 * @var PressForward\Interfaces\System
	 */
	public $system;

	/**
	 * Master field.
	 *
	 * @access public
	 * @var string
	 */
	public $master_field;

	/**
	 * Constructor.
	 *
	 * @param PressForward\Interfaces\SystemMeta $metas  SystemMeta object.
	 * @param PressForward\Interfaces\System     $system System object.
	 */
	public function __construct( SystemMeta $metas, System $system ) {
		$this->meta_interface = $metas;
		$this->system         = $system;
		$this->master_field   = 'pf_meta';
	}

	/**
	 * Sets up filter hooks for this class.
	 *
	 * @return array
	 */
	public function filter_hooks() {
		$filters = array(
			array(
				'hook'     => 'get_post_metadata',
				'method'   => 'usable_forward_to_origin_status',
				'priority' => 10,
				'args'     => 4,
			),
		);

		return $filters;
	}

	/**
	 * Sets up action hooks for this class.
	 *
	 * @return array
	 */
	public function action_hooks() {
		$filters = array(
			array(
				'hook'     => 'init',
				'method'   => 'register_pf_metas',
				'priority' => 10,
			),
		);

		return $filters;
	}

	/**
	 * Take an array of objects describing post_metas and set them to the id of a post.
	 *
	 * @since 3.x
	 *
	 * @param int   $id   A post object ID number.
	 * @param array $args {
	 *   An array of objects containing post_meta data.
	 *   @var string $name The post_meta slug.
	 *   @var string $value The post_meta's value.
	 * }
	 */
	public function establish_post( $id, $args ) {
		foreach ( $args as $arg ) {
			$this->add_pf_meta( $id, $arg['name'], $arg['value'], true );
		}
	}

	/**
	 * Takes a post_meta name and a post_meta value and turns it into an for use.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 * @return array An array useful in thevarious parts of the post_meta setting process.
	 */
	public function meta_for_entry( $key, $value ) {
		return array(
			'name'  => $key,
			'value' => $value,
		);
	}

	/**
	 * With two post IDs copy all the standard PressForward meta from one post to another.
	 *
	 * @param int  $id_a            The ID of the post that has all the meta info already set.
	 * @param int  $id_b            The ID of the post that needs to have the meta info attached to it.
	 * @param bool $term_transition Whether to transition terms.
	 */
	public function transition_post_meta( $id_a, $id_b, $term_transition = false ) {
		if ( ( ! is_string( $id_a ) || ! is_string( $id_b ) ) && ( ! is_numeric( $id_a ) || ! is_numeric( $id_b ) ) ) {
			pf_log( 'Post meta transition failed.' );
			pf_log( $id_a );
			pf_log( $id_b );
			pf_log( $term_transition );

			return;
		}
		pf_log( 'Transition post ' . $id_a . ' to ' . $id_b );
		foreach ( $this->structure() as $meta ) {
			$post_types  = apply_filters( 'pf_transition_post_meta', array( 'item', 'nomination', 'post' ) );
			$level_check = false;
			foreach ( $meta['level'] as $level ) {
				if ( in_array( $level, $post_types, true ) ) {
					$level_check = true;
					break;
				}
			}

			if ( $level_check ) {
				$this->transition_meta( $this->get_name( $meta ), $id_a, $id_b );
			} else {
				continue;
			}
		}
		if ( $term_transition ) {
			pf_log( 'Transitioning Terms.' );
			$this->transition_meta_terms( $id_a, $id_b );
		}
	}

	/**
	 * Transitions terms from one item to another.
	 *
	 * @param int $id_a ID of the first item.
	 * @param int $id_b ID of the second item.
	 * @return void
	 */
	public function transition_meta_terms( $id_a, $id_b ) {
		$parent = wp_get_post_parent_id( $id_a );
		$ids    = array( $id_a );
		if ( ! empty( $parent ) ) {
			$ids[] = $parent;
		}
		$item_id = $this->get_post_pf_meta( $id_a, 'pf_item_post_id' );
		if ( ! empty( $item_id ) && ! is_wp_error( $item_id ) ) {
			$ids[] = $item_id;
		}

		$term_objects = wp_get_object_terms( $ids, array( pressforward( 'schema.feeds' )->tag_taxonomy, 'post_tag', 'category' ) );
		$item_tags    = $this->get_post_pf_meta( $id_a, 'item_tags' );
		if ( ! empty( $term_objects ) ) {
			foreach ( $term_objects as $term ) {
				wp_set_object_terms( $id_b, $term->term_id, $term->taxonomy, true );
				if ( pressforward( 'schema.feeds' )->tag_taxonomy === $term->taxonomy ) {
					$check = $this->cascade_taxonomy_tagging( $id_b, $term->slug, 'slug' );
					if ( ! $check ) {
						$this->build_and_assign_new_taxonomy_tag( $id_b, $term->name );
					}
				}
			}
		}
		$this->handle_item_tags( $id_b, $item_tags );
	}

	/**
	 * Handles attaching tags to an item.
	 *
	 * @param int   $id_b      ID of the item.
	 * @param array $item_tags Array of tags.
	 * @return array
	 */
	public function handle_item_tags( $id_b, $item_tags ) {
		if ( ! empty( $item_tags ) ) {
			pf_log( 'Attempting to attach item_tags.' );
			if ( ! is_array( $item_tags ) ) {
				pf_log( $item_tags );
				$item_tags = explode( ',', $item_tags );
			}
			foreach ( $item_tags as $key => $tag ) {
				$tag               = trim( $tag );
				$item_tags[ $key ] = $tag;
				$check             = $this->cascade_taxonomy_tagging( $id_b, $tag, 'name' );
				if ( ! $check ) {
					$this->build_and_assign_new_taxonomy_tag( $id_b, $tag );
				}
			}

			return $item_tags;
		} else {
			return array();
		}
	}

	/**
	 * If term exists among current categories or terms, assign it.
	 *
	 * @param int    $id_b         Post ID.
	 * @param mixed  $term_id      Identifier for term.
	 * @param string $term_id_type Field of `$term_id`.
	 * @return mixed
	 */
	public function cascade_taxonomy_tagging( $id_b, $term_id, $term_id_type = 'slug' ) {
		pf_log( 'Trying to assign taxonomy for ' . $id_b );
		$term_object = get_term_by( $term_id_type, $term_id, 'category' );
		if ( empty( $term_object ) ) {
			pf_log( 'No category match.' );
			$term_object = get_term_by( $term_id_type, $term_id, 'post_tag' );
			if ( empty( $term_object ) ) {
				pf_log( 'No post_tag match.' );

				return false;
			} else {
				return wp_set_object_terms( $id_b, intval( $term_object->term_id ), 'post_tag', true );
			}
		} else {
			return wp_set_object_terms( $id_b, intval( $term_object->term_id ), 'category', true );
		}

		return true;
	}

	/**
	 * When no tag exists, PF will use this function to build and assign a new
	 * post tag.
	 *
	 * @param int    $id_b          Item ID.
	 * @param string $full_tag_name Tag name.
	 * @return void
	 */
	public function build_and_assign_new_taxonomy_tag( $id_b, $full_tag_name ) {
		pf_log( 'Attaching new tag to ' . $id_b . ' with a name of ' . $full_tag_name );
		$term_args = array(
			'description' => 'Added by PressForward',
			'parent'      => 0,
			'slug'        => pf_slugger( $full_tag_name ),
		);
		$r         = wp_insert_term( $full_tag_name, 'post_tag', $term_args );
		if ( ! is_wp_error( $r ) && ! empty( $r['term_id'] ) ) {
			pf_log( 'Making a new post_tag, ID:' . $r['term_id'] );
			wp_set_object_terms( $id_b, intval( $r['term_id'] ), 'post_tag', true );
		} else {
			pf_log( 'Failed making a new post_tag' );
			pf_log( $r );
		}
	}

	/**
	 * With a post_meta slug and two post IDs copy a post_meta from one post to another.
	 *
	 * @param string $name The post_meta slug.
	 * @param int    $id_a The post which already has the post_meta data.
	 * @param int    $id_b The post which needs the post_meta copied to it.
	 * @return int the result of the update_post_meta function
	 */
	public function transition_meta( $name, $id_a, $id_b ) {
		$meta_value = $this->meta_interface->get_meta( $id_a, $name, true );
		$result     = $this->check_for_and_transfer_depreciated_meta( $name, $meta_value, $id_a, $id_b );
		if ( ! $result ) {
			$result = $this->meta_interface->update_meta( $id_b, $name, $meta_value );
		}

		return $result;
	}

	/**
	 * Check a post_meta slug and insure that the correct post_meta is being set.
	 *
	 * Considers a post_meta slug and checkes it against a list for depreciation.
	 * If the post_meta slug has been depreciated update the new slug and the old one.
	 *
	 * Based on http://seoserpent.com/wordpress/custom-author-byline
	 *
	 * @since 3.x
	 *
	 * @param string $name  The post_meta slug.
	 * @param string $value The post_meta value.
	 * @param int    $id_a  The id of the post that already has the post_meta set.
	 * @param int    $id_b  The id of the post that needs the post_meta set.
	 *
	 * @return bool true if the post_meta is supported by PressForward
	 */
	public function check_for_and_transfer_depreciated_meta( $name, $value, $id_a, $id_b ) {
		foreach ( $this->structure() as $meta ) {
			if ( $meta['name'] === $name ) {
				if ( in_array( 'dep', $meta['type'], true ) ) {
					pf_log( $name . ' is a deprecated meta type. Prepping to transfer to ' . $meta['move'] );
					if ( ( ! isset( $value ) ) || ( false === $value ) || ( '' === $value ) || ( 0 === $value ) || ( empty( $value ) ) ) {
						pf_log( 'No value was passed. Get meta data from new meta key.' );
						$value = $this->meta_interface->get_meta( $id_a, $meta['move'], true );
					}
					$this->meta_interface->update_meta( $id_b, $meta['move'], $value );

					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get the meta by its name, if it is supported by PressForward.
	 *
	 * @since 3.x
	 *
	 * @param string $name The meta name we're checking to see if it is an
	 *                     an official PF meta.
	 * @return string|bool returns PF meta object, false if not
	 */
	public function by_name( $name ) {
		foreach ( $this->structure() as $meta ) {
			if ( $name === $meta['name'] ) {
				return $meta;
			} else {
				pf_log( $name . ' is not a PF meta type.' );

				return false;
			}
		}
	}

	/**
	 * Return a PF Meta Object that is assuredly not depreciated.
	 *
	 * @param string $name Meta name.
	 * @return array
	 */
	public function assure_key( $name ) {
		$meta = $this->by_name( $name );
		if ( ( false !== $meta ) && ! empty( $meta['move'] ) ) {
			return $this->by_name( $meta['move'] );
		} elseif ( false !== $meta ) {
			return $meta;
		} else {
			pf_log( $name . ' is not PF meta.' );

			return array(
				'name'  => $name,
				'error' => 'not_pf_meta',
			);
		}
	}

	/**
	 * Return the meta database key.
	 *
	 * @param string $name Key.
	 * @return string
	 */
	public function get_key( $name ) {
		$meta = $this->assure_key( $name );

		return $this->get_name( $meta );
	}

	/**
	 * Get the name (database key) out of the meta object.
	 *
	 * @param array $meta Meta array.
	 * @return string
	 */
	public function get_name( $meta ) {
		return $meta['name'];
	}

	/**
	 * Get an array representing all the approved post_meta objects for PressForward.
	 *
	 * @since 3.x
	 *
	 * @return array an object describing all the post_metas used by PressForward
	 */
	public function structure() {
		// Inspired by http://www.loc.gov/standards/metable.html
		// Adm=Administrative, Struc=Structural, Desc=Descriptive, Req=Required, Rep=Repeatable, Set=Set, Aggr=Aggregate, Dep = Depreciated.
		$metas = array(
			'item_id'                 => array(
				'name'       => 'item_id',
				'title'      => __( 'PressForward ID', 'pf' ),
				'definition' => __( 'Unique PressForward ID', 'pf' ),
				'function'   => __( 'Stores hashed ID based on title and URL of retrieved item', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array( 'req', 'api' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => false,
			),
			'pf_meta'                 => array(
				'name'       => 'pf_meta',
				'title'      => __( 'PressForward Metas', 'pf' ),
				'definition' => __( 'Serialized PF data', 'pf' ),
				'function'   => __( 'Array of PF data that can be serialized', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array( 'req' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => false,
			),
			'origin_item_ID'          => array(
				'name'       => 'origin_item_ID',
				'title'      => __( 'PressForward ID', 'pf' ),
				'definition' => __( 'DUPE Soon to be depreciated version of item_id', 'pf' ),
				'function'   => __( 'Stores hashed ID based on title and URL of retrieved item', 'pf' ),
				'type'       => array( 'struc', 'dep' ),
				'use'        => array( 'req' ),
				'move'       => 'item_id',
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => true,
			),
			'pf_item_post_id'         => array(
				'name'       => 'pf_item_post_id',
				'title'      => __( 'Item WordPress ID', 'pf' ),
				'definition' => __( 'The WordPress Post ID associated with the original item', 'pf' ),
				'function'   => __( 'Stores hashed WP post_ID associated with the original item', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array( 'req' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => false,
			),
			'nom_id'                  => array(
				'name'       => 'nom_id',
				'title'      => __( 'Nomination WordPress ID', 'pf' ),
				'definition' => __( 'The WordPress postID associated with the nomination item', 'pf' ),
				'function'   => __( 'Stores nomination id', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array( 'req' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => false,
			),
			'pf_final_step_id'        => array(
				'name'       => 'pf_final_step_id',
				'title'      => __( 'Final Step WordPress ID', 'pf' ),
				'definition' => __( 'The WordPress postID associated with the post on the final step', 'pf' ),
				'function'   => __( 'Stores postID associated with the last step item', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array(),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => true,
			),
			'item_feed_post_id'       => array(
				'name'       => 'item_feed_post_id',
				'title'      => __( 'Item WordPress ID', 'pf' ),
				'definition' => __( 'DUPE Soon to be depreciated version of pf_item_post_id', 'pf' ),
				'function'   => __( 'Stores hashed ID based on title and URL of retrieved item', 'pf' ),
				'type'       => array( 'struc', 'dep' ),
				'use'        => array( 'req' ),
				'move'       => 'pf_item_post_id',
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => true,
			),
			'source_title'            => array(
				'name'       => 'source_title',
				'title'      => __( 'Source Title', 'pf' ),
				'definition' => __( 'Title of the item\'s source', 'pf' ),
				'function'   => __( 'Stores the title retrieved from the feed.', 'pf' ),
				'type'       => array( 'adm', 'aggr' ),
				'use'        => array( 'api' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => true,
			),
			'pf_source_link'          => array(
				'name'       => 'pf_source_link',
				'title'      => __( 'Origin Item URL', 'pf' ),
				'definition' => __( 'URL of the item\'s source', 'pf' ),
				'function'   => __( 'Stores the url of feed source.', 'pf' ),
				'type'       => array( 'adm', 'aggr' ),
				'use'        => array( 'api' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => true,
			),
			'pf_feed_item_source'     => array(
				'name'       => 'pf_feed_item_source',
				'title'      => __( 'Source Title', 'pf' ),
				'definition' => __( 'DUPE Soon to be depreciate version of source_title.', 'pf' ),
				'function'   => __( 'Stores the title retrieved from the feed.', 'pf' ),
				'type'       => array( 'desc', 'dep' ),
				'use'        => array( 'req' ),
				'move'       => 'source_title',
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => true,
			),
			'item_date'               => array(
				'name'       => 'item_date',
				'title'      => __( 'Date Posted on Source', 'pf' ),
				'definition' => __( 'Date posted on the original site', 'pf' ),
				'function'   => __( 'Stores the date the item was posted on the original site', 'pf' ),
				'type'       => array( 'desc' ),
				'use'        => array( 'req', 'api' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => false,
			),
			'posted_date'             => array(
				'name'       => 'posted_date',
				'title'      => __( 'Date Posted on Source', 'pf' ),
				'definition' => __( 'DUPE The soon to be depreciated version of item_date', 'pf' ),
				'function'   => __( 'Stores the date given by the source.', 'pf' ),
				'type'       => array( 'struc', 'dep' ),
				'use'        => array( 'req' ),
				'move'       => 'item_date',
				'level'      => array( 'nomination', 'post' ),
				'serialize'  => true,
			),
			'item_author'             => array(
				'name'       => 'item_author',
				'title'      => __( 'Author(s)', 'pf' ),
				'definition' => __( 'Author(s) listed on the original site', 'pf' ),
				'function'   => __( 'Stores array value containing authors listed in the source feed.', 'pf' ),
				'type'       => array( 'struc', 'adm' ),
				'use'        => array( 'api' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => false,
			),
			'authors'                 => array(
				'name'       => 'authors',
				'title'      => __( 'Author(s)', 'pf' ),
				'definition' => __( 'DUPE The soon to be depreciated version of item_author', 'pf' ),
				'function'   => __( 'Stores a comma-separated set of authors as listed in the source feed', 'pf' ),
				'type'       => array( 'struc', 'dep' ),
				'use'        => array(),
				'move'       => 'item_author',
				'level'      => array( 'nomination', 'post' ),
				'serialize'  => true,
			),
			'item_link'               => array(
				'name'       => 'item_link',
				'title'      => __( 'Link to Source', 'pf' ),
				'definition' => __( 'Source link', 'pf' ),
				'function'   => __( 'Stores link to the origonal post.', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array( 'req', 'api' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => false,
			),
			'nomination_permalink'    => array(
				'name'       => 'item_link',
				'title'      => __( 'Link to Source', 'pf' ),
				'definition' => __( 'Source link', 'pf' ),
				'function'   => __( 'DUPE Soon to be depreciated version of item_link', 'pf' ),
				'type'       => array( 'struc', 'dep' ),
				'use'        => array( 'req' ),
				'move'       => 'item_link',
				'level'      => array( 'nomination', 'post' ),
				'serialize'  => true,
			),
			'item_feat_img'           => array(
				'name'       => 'item_feat_img',
				'title'      => __( 'Featured Image', 'pf' ),
				'definition' => __( 'Featured image from source', 'pf' ),
				'function'   => __( 'A featured image associated with the item, when it is available', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array(),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => true,
			),
			'item_wp_date'            => array(
				'name'       => 'item_wp_date',
				'title'      => __( 'Date added to PressForward', 'pf' ),
				'definition' => __( 'Time item was retrieved', 'pf' ),
				'function'   => __( 'The datetime an item was added to WordPress via PressForward', 'pf' ),
				'type'       => array( 'desc' ),
				'use'        => array( 'req', 'api' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => false,
			),
			'date_nominated'          => array(
				'name'       => 'date_nominated',
				'title'      => __( 'Nomination Datetime', 'pf' ),
				'definition' => __( 'Time nominated', 'pf' ),
				'function'   => __( 'The datetime the item was made a nomination', 'pf' ),
				'type'       => array( 'desc' ),
				'use'        => array( 'req', 'api' ),
				'level'      => array( 'nomination', 'post' ),
				'serialize'  => true,
			),
			'item_tags'               => array(
				'name'       => 'item_tags',
				'title'      => __( 'Tags', 'pf' ),
				'definition' => __( 'Tags associated with the item by source', 'pf' ),
				'function'   => __( 'An array of tags associated with the item, as created in the feed', 'pf' ),
				'type'       => array( 'desc', 'adm', 'aggr' ),
				'use'        => array(),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => true,
			),
			'source_repeat'           => array(
				'name'       => 'source_repeat',
				'title'      => __( 'Number of Sources With Item', 'pf' ),
				'definition' => __( 'Times retrieved', 'pf' ),
				'function'   => __( 'Counts number of times the item has been collected from the multiple feeds (Ex: from origin feed and Twitter)', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array( 'api' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => true,
			),
			'nomination_count'        => array(
				'name'       => 'nomination_count',
				'title'      => __( 'Times Nominated', 'pf' ),
				'definition' => __( 'Nominations', 'pf' ),
				'function'   => __( 'Counts number of times users have nominated an item', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array( 'req', 'api' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => false,
			),
			'submitted_by'            => array(
				'name'       => 'submitted_by',
				'title'      => __( 'Submitted By', 'pf' ),
				'definition' => __( 'The user who submitted the nomination', 'pf' ),
				'function'   => __( 'The first user who submitted the nomination (if it has been nominated). User ID number', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array( 'req', 'api' ),
				'level'      => array( 'nomination', 'post' ),
				'serialize'  => true,
			),
			'nominator_array'         => array(
				'name'       => 'nominator_array',
				'title'      => __( 'Nominators', 'pf' ),
				'definition' => __( 'Users who nominated this item', 'pf' ),
				'function'   => __( 'Stores and array of all userIDs that nominated the item in an array', 'pf' ),
				'type'       => array( 'adm' ),
				'use'        => array( 'req', 'api' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => true,
			),
			'sortable_item_date'      => array(
				'name'       => 'sortable_item_date',
				'title'      => __( 'Timestamp of Item Date', 'pf' ),
				'definition' => __( 'Timestamp for the item', 'pf' ),
				'function'   => __( 'A version of the item_date meta that\'s ready for sorting. Should be a Unix timestamp', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array( 'req' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => false,
			),
			'readable_status'         => array(
				'name'       => 'readable_status',
				'title'      => __( 'Is Readable?', 'pf' ),
				'definition' => __( 'If the content is readable', 'pf' ),
				'function'   => __( 'A check to determine if the content of the item has been made readable', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array( 'req', 'api' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => true,
			),
			'revertible_feed_text'    => array(
				'name'       => 'revertible_feed_text',
				'title'      => __( 'Original Text', 'pf' ),
				'definition' => __( 'The originally retrieved description', 'pf' ),
				'function'   => __( 'The original description, excerpt or content text given by the feed', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array(),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => true,
			),
			'pf_feed_item_word_count' => array(
				'name'       => 'pf_feed_item_word_count',
				'title'      => __( 'Original Wordcount', 'pf' ),
				'definition' => __( 'Word count of original item text', 'pf' ),
				'function'   => __( 'Stores the count of the original words retrieved with the feed item', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array( 'api' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => true,
			),
			'pf_word_count'           => array(
				'name'       => 'pf_word_count',
				'title'      => __( 'Most Recent Wordcount', 'pf' ),
				'definition' => __( 'Word count of text', 'pf' ),
				'function'   => __( 'Stores the count of the words on the last save managed by PF.', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array( 'api' ),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => false,
			),
			'pf_archive'              => array(
				'name'       => 'pf_archive',
				'title'      => __( 'Archived', 'pf' ),
				'definition' => __( 'Archive state of the item', 'pf' ),
				'function'   => __( 'Stores if the item has been archived', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array(),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => false,
			),
			'_thumbnail_id'           => array(
				'name'       => '_thumbnail_id',
				'title'      => __( 'Thumbnail ID', 'pf' ),
				'definition' => __( 'Thumbnail id', 'pf' ),
				'function'   => __( 'The ID of the featured item', 'pf' ),
				'type'       => array( 'adm', 'struc' ),
				'use'        => array(),
				'level'      => array( 'item', 'nomination', 'post' ),
				'serialize'  => false,
			),
			'archived_by_user_status' => array(
				'name'       => 'archived_by_user_status',
				'title'      => __( 'Users Archived', 'pf' ),
				'definition' => __( 'Users who have archived', 'pf' ),
				'function'   => __( 'Stores users who have archived.', 'pf' ),
				'type'       => array( 'struc' ),
				'use'        => array(),
				'level'      => array( 'item', 'nomination' ),
				'serialize'  => true,
			),
			'pf_feed_error_count'     => array(
				'name'       => 'pf_feed_error_count',
				'title'      => __( 'Feed Errors', 'pf' ),
				'definition' => __( 'Count of feed errors', 'pf' ),
				'function'   => __( 'Stores a count of the number of errors a feed has experianced', 'pf' ),
				'type'       => array( 'adm' ),
				'use'        => array(),
				'level'      => array( 'feed', 'post' ),
				'serialize'  => true,
			),
			'pf_forward_to_origin'    => array(
				'name'       => 'pf_forward_to_origin',
				'title'      => __( 'Forward to origin', 'pf' ),
				'definition' => __( 'User override for forwarding to origin of link', 'pf' ),
				'function'   => __( 'Stores forwarding override for posts', 'pf' ),
				'type'       => array( 'adm' ),
				'use'        => array( 'api' ),
				'level'      => array( 'post' ),
				'serialize'  => false,
			),
			'pf_feed_last_retrieved'  => array(
				'name'       => 'pf_feed_last_retrieved',
				'title'      => __( 'Last time feed retrieved', 'pf' ),
				'definition' => __( 'Last time feed was retrieved', 'pf' ),
				'function'   => __( 'Stores last timestamp feed was retrieved.', 'pf' ),
				'type'       => array( 'adm' ),
				'use'        => array( 'api' ),
				'level'      => array( 'feed' ),
				'serialize'  => false,
			),
			'pf_nominations_in_feed'  => array(
				'name'       => 'pf_nominations_in_feed',
				'title'      => __( 'Nominations received by feed', 'pf' ),
				'definition' => __( 'Nominations received by items supplied by this feed', 'pf' ),
				'function'   => __( 'Number of nominations for this feed', 'pf' ),
				'type'       => array( 'adm' ),
				'use'        => array( 'api' ),
				'level'      => array( 'feed' ),
				'serialize'  => false,
			),
			'pf_feed_default_author'  => array(
				'name'       => 'pf_feed_default_author',
				'title'      => __( 'Default Feed Author', 'pf' ),
				'definition' => __( 'The default author set for items in the feed', 'pf' ),
				'function'   => __( 'Stores the default author that is used when no author is available in the feed.', 'pf' ),
				'type'       => array( 'adm' ),
				'use'        => array( 'api' ),
				'level'      => array( 'feed' ),
				'serialize'  => false,
			),
			'feedUrl'                 => array(
				'name'       => 'feedUrl',
				'title'      => __( 'Feed URL', 'pf' ),
				'definition' => __( 'URL for a feed', 'pf' ),
				'function'   => __( 'Stores location online for feed.', 'pf' ),
				'type'       => array( 'adm' ),
				'use'        => array( 'api' ),
				'level'      => array( 'feed' ),
				'serialize'  => false,
			),
			'pf_feed_last_checked'    => array(
				'name'       => 'pf_feed_last_checked',
				'title'      => __( 'Last time feed checked', 'pf' ),
				'definition' => __( 'Last time feed was checked', 'pf' ),
				'function'   => __( 'Stores last timestamp feed was checked.', 'pf' ),
				'type'       => array( 'adm' ),
				'use'        => array(),
				'level'      => array( 'feed' ),
				'serialize'  => false,
			),
			'pf_no_feed_alert'        => array(
				'name'       => 'pf_no_feed_alert',
				'title'      => __( 'Feed alert status', 'pf' ),
				'definition' => __( 'Feed Alert Status', 'pf' ),
				'function'   => __( 'A check to see if an alert is on the feed.', 'pf' ),
				'type'       => array( 'adm' ),
				'use'        => array(),
				'level'      => array( 'feed' ),
				'serialize'  => false,
			),
			'feed_type'               => array(
				'name'       => 'feed_type',
				'title'      => __( 'Feed type', 'pf' ),
				'definition' => __( 'Type of feed', 'pf' ),
				'function'   => __( 'Field stores the type of feed (like RSS or OPML) the object holds.', 'pf' ),
				'type'       => array( 'adm' ),
				'use'        => array( 'api' ),
				'level'      => array( 'feed' ),
				'serialize'  => false,
			),
			'htmlUrl'                 => array(
				'name'       => 'htmlUrl',
				'title'      => __( 'Site URL', 'pf' ),
				'definition' => __( 'Site URL of a feed.', 'pf' ),
				'function'   => __( 'The home URL of a feed.', 'pf' ),
				'type'       => array( 'adm' ),
				'use'        => array( 'api' ),
				'level'      => array( 'feed' ),
				'serialize'  => false,
			),
			'user_added'              => array(
				'name'       => 'user_added',
				'title'      => __( 'User who added feed', 'pf' ),
				'definition' => __( 'User who added a feed.', 'pf' ),
				'function'   => __( 'Track who added a subscribed or under review feed.', 'pf' ),
				'type'       => array( 'adm', 'struc' ),
				'use'        => array( 'api' ),
				'level'      => array( 'feed' ),
				'serialize'  => false,
			),
			'module_added'            => array(
				'name'       => 'module_added',
				'title'      => __( 'Handling Module', 'pf' ),
				'definition' => __( 'Module to process a feed.', 'pf' ),
				'function'   => __( 'The feed should be processed with this module.', 'pf' ),
				'type'       => array( 'adm', 'struc' ),
				'use'        => array( 'api' ),
				'level'      => array( 'feed' ),
				'serialize'  => false,
			),
			'ab_alert_msg'            => array(
				'name'       => 'ab_alert_msg',
				'title'      => __( 'Alert Message', 'pf' ),
				'definition' => __( 'Alert Message processing and storage.', 'pf' ),
				'function'   => __( 'Stores a feed alert to be processed.', 'pf' ),
				'type'       => array( 'adm' ),
				'use'        => array( 'api' ),
				'level'      => array( 'feed' ),
				'serialize'  => false,
			),
			'pf_meta_data_check'      => array(
				'name'       => 'pf_meta_data_check',
				'title'      => __( 'Metadata checked?', 'pf' ),
				'definition' => __( 'Has metadata been compleatly added to a feed?', 'pf' ),
				'function'   => __( 'Store a value to indicate the meta-processing of a feed has completed.', 'pf' ),
				'type'       => array( 'adm' ),
				'use'        => array(),
				'level'      => array( 'feed' ),
				'serialize'  => false,
			),
			'pf_source_statement'     => array(
				'name'       => 'pf_source_statement',
				'title'      => __( 'Source statement string', 'pf' ),
				'definition' => __( 'The string containing the "Source" statement appended to an item.', 'pf' ),
				'function'   => '',
				'type'       => array( 'adm' ),
				'use'        => array(),
				'level'      => array( 'feed', 'item', 'nomination', 'post' ),
				'serialize'  => false,
			),
		);

		$metas = apply_filters( 'pf_meta_terms', $metas );

		return $metas;
	}

	/**
	 * Maps post type to meta "level".
	 *
	 * @param string $post_type Post type.
	 * @return bool|string
	 */
	public function map_post_type_to_level( $post_type ) {
		$mapping = array(
			'feed'       => 'pf_feed',
			'item'       => 'pf_feed_item',
			'nomination' => 'nomination',
			'post'       => 'post',
		);
		$mapping = array(
			'pf_feed'      => 'feed',
			'pf_feed_item' => 'item',
			'nomination'   => 'nomination',
			'post'         => 'post',
		);
		$mapping = apply_filters( 'pf_post_type_to_level', $mapping );
		if ( array_key_exists( $post_type, $mapping, true ) ) {
			return $mapping[ $post_type ];
		} else {
			return false;
		}
	}

	/**
	 * Attaches metadata to a post object based on the intended use context.
	 *
	 * @param WP_Post $post_object Post object.
	 * @param string  $use_context Context of use.
	 * @param bool    $admin       Whether it's an admin request. Default false.
	 * @return WP_Post
	 */
	public function attach_metas_by_use( $post_object, $use_context = 'api', $admin = false ) {
		$post_id = $post_object->ID;
		foreach ( $this->structure() as $key => $meta ) {
			if ( in_array( $use, $meta['use'], true ) ) {
				if ( in_array( $this->map_post_type_to_level( $post_object->post_type ), $meta['level'], true ) ) {
					if ( false === $admin ) {
						if ( ! in_array( 'adm', $meta['type'], true ) ) {
							if ( ! property_exists( $post_object, $key ) ) {
								$post_object->$key = $this->get_post_pf_meta( $post_id, $meta['name'] );
							}
						}
					} elseif ( ! isset( $post_object->$key ) ) {
							$post_object->$key = $this->get_post_pf_meta( $post_id, $meta['name'] );
					}
				}
			}
		}
		return $post_object;
	}

	/**
	 * Register metas to prevent core from breaking when adding them to API.
	 *
	 * See https://developer.wordpress.org/reference/functions/register_meta/
	 * and WP_REST_Term_Meta_Fields.
	 *
	 * @return [type] [description]
	 */
	public function register_pf_metas() {
		$metas = array();
		foreach ( $this->structure() as $key => $meta ) {
			if ( $meta['serialize'] ) {
				continue;
			}
			if ( ! in_array( 'api', $meta['use'], true ) ) {
				continue;
			}
			$metas[ $key ]                 = array();
			$metas[ $key ]['show_in_rest'] = true;
			$metas[ $key ]['single']       = true;
			$metas[ $key ]['type']         = 'string';
			$metas[ $key ]['show_in_rest'] = false;
			$metas[ $key ]['description']  = $meta['function'];
			foreach ( $meta['level'] as $level ) {
				switch ( $level ) {
					case 'item':
						register_meta( pressforward( 'schema.feed_item' )->post_type, $key, $metas[ $key ] );
						break;
					case 'nomination':
						register_meta( pressforward( 'schema.nominations' )->post_type, $key, $metas[ $key ] );
						break;
					case 'post':
						register_meta( 'post', $key, $metas[ $key ] );
						break;
					case 'feed':
						register_meta( pressforward( 'schema.feeds' )->post_type, $key, $metas[ $key ] );
						break;
					default:
						// code...
						break;
				}
			}
		}

		return true;
	}

	/**
	 * A function to check and retrieve the right meta field for a post.
	 *
	 * @param string $field     The post_meta field to retrieve.
	 * @param int    $id        Post ID.
	 * @param mixed  $value     Value.
	 * @param bool   $single    If the user wants to use the WordPress post_meta Single declaration. Default true.
	 * @return string
	 */
	public function pass_meta( $field, $id = false, $value = '', $single = true ) {
		$metas = $this->structure();

		// Check if it exists.
		if ( empty( $metas[ $field ] ) ) {
			pf_log( 'The field ' . $field . ' is not supported.' );

			return $field;
		}

		// Check if it has been depreciated (dep). If so retrieve.
		if ( in_array( 'dep', $metas[ $field ]['type'], true ) ) {
			$new_field = $metas[ $field ]['move'];
			pf_log( 'You tried to use depreciated field ' . $field . ' it was moved to ' . $new_field );
			$this->transition_depreciated_meta( $field, $id, $value, $single, $new_field );
			$field = $new_field;
		}

		if ( $metas[ $field ]['serialize'] ) {
			return array(
				'field'        => $field,
				'master_field' => $this->master_field,
			);
		}

		return $field;
	}

	/**
	 * Transitions meta values from old depreciated meta_slugs to new ones.
	 *
	 * @param string $field     The post_meta field to retrieve.
	 * @param int    $id        Post ID.
	 * @param mixed  $value     Value.
	 * @param bool   $single    If the user wants to use the WordPress post_meta Single declaration. Default true.
	 * @param string $new_field New meta slug.
	 */
	public function transition_depreciated_meta( $field, $id, $value, $single, $new_field ) {
		$result = false;
		// Note - empty checks for FALSE.
		$old = $this->meta_interface->get_meta( $id, $field, $single );
		$new = $this->meta_interface->get_meta( $id, $new_field, $single );
		if ( ! empty( $id ) && ! empty( $old ) && empty( $new ) ) {
			if ( empty( $value ) ) {
				$result = $this->meta_interface->update_meta( $id, $new_field, $old );
			} else {
				$result = $this->meta_interface->update_meta( $id, $new_field, $value );
			}
		}

		return $result;
	}

	/**
	 * Retrieve post_meta data in a way that insures the correct value is pulled.
	 *
	 * Function allows users to retrieve the post_meta in a safe way standerdizing against
	 * the list of accepted PressForward meta_slugs. It deals with depreciated post_meta.
	 *
	 * @since 3.x
	 *
	 * @param int    $id     Post ID.
	 * @param string $field  The post_meta field to retrieve.
	 * @param bool   $obj    If the user wants to return a PressForward post_meta description object. Default false.
	 * @param bool   $single If the user wants to use the WordPress post_meta Single decleration. Default true.
	 *
	 * @return string|array returns the result of retrieving the post_meta or the self-descriptive meta-object with value
	 */
	public function retrieve_meta( $id, $field, $obj = false, $single = true ) {
		$field      = $this->pass_meta( $field, $id );
		$serialized = false;
		if ( is_array( $field ) ) {
			$key        = $field['field'];
			$field      = $field['master_field'];
			$serialized = true;
			$single     = true;
		}
		$meta = $this->meta_interface->get_meta( $id, $field, $single );
		if ( $serialized ) {
			if ( empty( $meta ) || ! array_key_exists( $key, $meta ) ) {
				if ( ! is_array( $meta ) ) {
					$meta = [];
				}
				$old_meta     = $this->meta_interface->get_meta( $id, $key, $single );
				$meta[ $key ] = $old_meta;
				$this->meta_interface->update_meta( $id, $field, $meta );
				$this->meta_interface->delete_meta( $id, $key, $old_meta );
				$meta = $old_meta;
			} else {
				$meta = $meta[ $key ];
			}

			$meta = $this->check_value( $meta, $id, $key );
		} else {
			$meta = $this->check_value( $meta, $id, $field );
		}

		if ( $obj ) {
			$metas             = $this->structure();
			$meta_obj          = $metas[ $field ];
			$meta_obj['value'] = $meta;

			return $meta_obj;
		}

		return $meta;
	}

	/**
	 * Checks a meta value.
	 *
	 * @param mixed  $meta_value Meta value.
	 * @param int    $id         Post ID.
	 * @param string $field      Field name.
	 * @return mixed
	 */
	public function check_value( $meta_value, $id, $field ) {
		switch ( $field ) {
			case 'item_link':
				if ( empty( $meta_value ) ) {
					$meta_value = pressforward( 'controller.system' )->get_the_guid( $id );
				}
				break;

			case 'source_title':
				if ( empty( $meta_value ) || is_wp_error( $meta_value ) ) {
					$meta_value = get_the_source_title( $id );
				}
				break;

			case 'item_author':
				if ( empty( $meta_value ) || 'aggregation' === $meta_value ) {
					$parent_value = pressforward( 'controller.metas' )->get_post_pf_meta( $id, 'pf_feed_default_author', true );
					if ( ! empty( $parent_value ) ) {
						$meta_value = $parent_value;
					}
				}
				break;

			default:
				// code...
				break;
		}

		return $meta_value;
	}

	/**
	 * An alias for $this->retrieve_meta that allows you to use the standard argument set from get_post_meta.
	 *
	 * @param int    $id     Post ID.
	 * @param string $field  The post_meta field to retrieve.
	 * @param bool   $single If the user wants to use the WordPress post_meta Single decleration. Default true.
	 * @param bool   $obj    If the user wants to return a PressForward post_meta description object. Default false.
	 */
	public function get_post_pf_meta( $id, $field, $single = true, $obj = false ) {
		return $this->retrieve_meta( $id, $field, $obj, $single );
	}

	/**
	 * Gets a list of all PF meta keys.
	 *
	 * @return array
	 */
	public function get_all_meta_keys() {
		$meta_keys = array();
		foreach ( $this->structure() as $meta ) {
			$meta_keys[] = $this->get_name( $meta );
		}

		return $meta_keys;
	}

	/**
	 * Gets all PF metadata belonging to a post.
	 *
	 * @param int $post_id The post ID.
	 * @return array
	 */
	public function get_all_metas( $post_id ) {
		$all_metas = $this->meta_interface->get_metas( $post_id );
		$structure = $this->structure();
		foreach ( $all_metas as $key => $meta ) {
			if ( isset( $structure[ $key ] ) && $structure[ $key ]['serialize'] ) {
				$all_metas[ $key ] = $this->get_post_pf_meta( $post_id, $key );
			}
		}

		return $all_metas;
	}

	/**
	 * Update post_meta on a post using PressForward post_meta standardization.
	 *
	 * @param int|string $id         The post ID.
	 * @param string     $field      The post_meta field slug.
	 * @param string     $value      The post_meta value.
	 * @param string     $prev_value The previous value to insure proper replacement.
	 *
	 * @return int the check value from update_post_meta
	 */
	public function update_pf_meta( $id, $field, $value = '', $prev_value = null ) {
		$field = $this->pass_meta( $field, $id, $value );
		$check = $this->apply_pf_meta( $id, $field, $value, $prev_value );

		return $check;
	}

	/**
	 * Gets the 'author' metadata from a remote resource.
	 *
	 * @param string $url Remote URL.
	 * @return string
	 */
	public function get_author_from_url( $url ) {
		$response = pressforward( 'controller.http_tools' )->get_url_content( $url, 'wp_remote_get' );
		if ( ! $response || is_wp_error( $response ) ) {
			return false;
		}

		$dom = new \DOMDocument();

		libxml_use_internal_errors( true );
		$dom->loadHTML( $response['body'] );
		libxml_use_internal_errors( false );

		$metas = $dom->getElementsByTagName( 'meta' );

		foreach ( $metas as $meta ) {
			$name = $meta->getAttribute( 'name' );
			if ( in_array( $name, [ 'author', 'Author', 'parsely-author', 'sailthru.author' ], true ) ) {
				$author_meta = $meta->getAttribute( 'content' );
				break;
			}

			$property = $meta->getAttribute( 'property' );
			if ( in_array( $property, [ 'author', 'Author' ], true ) ) {
				$author_meta = $meta->getAttribute( 'content' );
				break;
			}
		}

		if ( empty( $author_meta ) ) {
			return false;
		}

		$author = trim( str_replace( 'by', '', $author_meta ) );
		$author = trim( str_replace( 'By', '', $author ) );

		return $author;
	}

	/**
	 * Add post_meta on a post using PressForward post_meta standardization.
	 *
	 * @param int|string $id     the post ID.
	 * @param string     $field  the post_meta field slug.
	 * @param string     $value  the post_meta value.
	 * @param string     $unique if the post_meta is unique.
	 *
	 * @return int the check value from add_post_meta
	 */
	public function add_pf_meta( $id, $field, $value = '', $unique = true ) {
		$field = $this->pass_meta( $field, $id, $value, $unique );
		$check = $this->apply_pf_meta( $id, $field, $value, $unique, 'add' );
		if ( ! $check ) {
			$this->apply_pf_meta( $id, $field, $value, $unique, 'update' );
		}

		return $check;
	}

	/**
	 * Applies PF-standardize postmeta.
	 *
	 * @param int|string $id         The post ID.
	 * @param string     $field      The post_meta field slug.
	 * @param string     $value      The post_meta value.
	 * @param string     $state      Unique status of the postmeta.
	 * @param string     $apply_type 'update' or 'add'.
	 */
	public function apply_pf_meta( $id, $field, $value = '', $state = null, $apply_type = 'update' ) {
		$serialized = false;
		if ( is_array( $field ) ) {
			$key        = $field['field'];
			$field      = $field['master_field'];
			$serialized = true;
		}

		if ( $serialized ) {
			$switch_value = $key;
		} else {
			$switch_value = $field;
		}
		switch ( $switch_value ) {
			case 'pf_feed_item_word_count':
				$latest_count = $this->get_post_pf_meta( $id, 'pf_word_count' );
				if ( ( $latest_count < $value ) ) {
					$this->update_pf_meta( $id, 'pf_word_count', $value, $state );
				} elseif ( empty( $latest_count ) ) {
					$this->add_pf_meta( $id, 'pf_word_count', $value, $state );
				}
				break;
			case 'item_author':
				$value = \trim( $value );
				break;
			default:
				// code...
				break;
		}
		if ( $serialized ) {
			$master_meta = $this->meta_interface->get_meta( $id, $field, true );
			if ( empty( $master_meta ) ) {
				$master_meta = array();
				$apply_type  = 'add';
				$state       = true;
			} else {
				$apply_type = 'update';
				$state      = $master_meta;
			}
			$master_meta[ $key ] = $value;
			$value               = $master_meta;
		}
		if ( 'update' === $apply_type ) {
			if ( $serialized ) {
				$this->meta_interface->delete_meta( $id, $key, '' );
			}
			$check = $this->meta_interface->update_meta( $id, $field, $value, $state );
			if ( ! $check ) {
				$check = $this->meta_interface->update_meta( $id, $field, $value, $state );
			}
		} elseif ( 'add' === $apply_type ) {
			$check = $this->meta_interface->add_meta( $id, $field, $value, $state );
		}

		return $check;
	}

	/**
	 * Gets the 'forward to origin' status of a post.
	 *
	 * @param int    $id        ID of the post.
	 * @param bool   $check     Whether to query the database.
	 * @param string $the_value Option value.
	 * @return string
	 */
	public function forward_to_origin_status( $id, $check = true, $the_value = false ) {
		$item_id = pressforward( 'controller.metas' )->get_post_pf_meta( $id, 'item_id', true );
		if ( empty( $item_id ) ) {
			return 'no-forward';
		}
		if ( $check ) {
			$value = pressforward( 'controller.metas' )->get_post_pf_meta( $id, 'pf_forward_to_origin', true );
		} else {
			$value = $the_value;
		}
		if ( empty( $value ) ) {
			$option_value = get_option( 'pf_link_to_source' );
			if ( empty( $option_value ) ) {
				$value = 'no-forward';
			} else {
				$value = 'forward';
			}
		}

		return $value;
	}

	/**
	 * Filter of 'pf_forward_to_origin' postmeta, for REST API.
	 *
	 * @param mixed  $retval    For short-circuiting call.
	 * @param int    $object_id ID of the object.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    "Single" param to get_post_meta().
	 * @return mixed
	 */
	public function usable_forward_to_origin_status( $retval, $object_id, $meta_key, $single ) {
		if ( 'pf_forward_to_origin' !== $meta_key ) {
			return $retval;
		}
		remove_filter( 'get_post_metadata', array( $this, 'usable_forward_to_origin_status' ), 10 );
		$value = $this->forward_to_origin_status( $object_id );
		add_filter( 'get_post_metadata', array( $this, 'usable_forward_to_origin_status' ), 10, 4 );

		if ( ! $single ) {
			$value = array( $value );
		}

		return $value;
	}
}
