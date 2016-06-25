<?php
/**
 * Plugin Name: GitHub Wiki
 * Plugin URI: https://www.usabilitydynamics.com
 * Description: Get GitHub Wiki.
 * Author: Usability Dynamics, Inc.
 * Version: 1.9.0
 * Text Domain: wp-github-wiki
 * Author URI: https://www.usabilitydynamics.com
 *
 * Copyright 2012 - 2016 Usability Dynamics, Inc.  ( email : info@usabilitydynamics.com )
 *
 */
namespace UsabilityDynamics\GitHubWiki {

  // Add API endpoints.
  $_api_path = apply_filters( 'wp-github-wiki/api_path',  '/v1/github/wiki' );

  if( $_api_path && $_api_path ) {
    add_action( 'wp_ajax_' . $_api_path, 'UsabilityDynamics\GitHubWiki\api_github_wiki' );
    add_action( 'wp_ajax_nopriv_' . $_api_path, 'UsabilityDynamics\GitHubWiki\api_github_wiki' );
  }

  // Request Actions.
  add_action( 'init', 'UsabilityDynamics\GitHubWiki\Actions::init', 5 );
  //add_action( 'the_post', 'UsabilityDynamics\GitHubWiki\Actions::the_post', 5 );

  /**
   * http://b49b413d4ffd.c.wpcloud.io/wp-admin/admin-ajax.php?action=/v1/github/wiki
   *
   *
   * WC products store their "full_name" in software_title meta key.
   *
   * wp post  list --post_type=github_wiki_doc --fields=ID --format=csv
   *
   * @todo Add basic authentication via AUTH_KEY constant.
   * @todo Get user_id by wiki author.
   * @todo If unable to fetch and it already exists, trash it.
   *
   */
  function api_github_wiki() {

    $json = file_get_contents('php://input');
    $obj = json_decode($json);

    // Brief valication.
    if( !$obj->pages || !$obj->pages[0] || !$obj->pages[0]->page_name ) {
      wp_send_json_error(array( "ok" => false, "message" => "Missing some information." ));
    }

    if( !get_option( 'wp-github-wiki/access_token' ) ) {
      wp_send_json_error(array( "ok" => false, "message" => "API not configured, missing GitHub Access Token." ));
    }

    $_result = array();

    foreach( (array) $obj->pages as $_index => $_page ) {

      $_result[$_index] = array(
        "ok" => null,
        "post_id" => null,
        "terms" => null,
      );

      // For now we skip Sidebar, in future we'll use it to set order and structure.
      if( $_page->page_name === '_Sidebar' ) {
        $_result[$_index]["ok"] = false;
        $_result[$_index]["message"] = "Skipping _Sidebar.";
        continue;
      }

      // Build Wiki Object
      $_wiki = array(
        "_name" => $_page->page_name,
        "_sha" => $_page->sha,
        "_action" => $_page->action,
        "_html_url" => $_page->html_url,
        "_product" => $obj->repository->name,
        "_full_name" => $obj->repository->full_name,
        "_url" => 'https://raw.githubusercontent.com/wiki/' . $obj->repository->full_name . '/' . $_page->page_name . '.md',
        "_author" => $obj->sender->login,
        "post_name" => strtolower( $_page->page_name ),
        "post_title" => $_page->title,
        "post_content" => "",
        "guid" => site_url( "/product/" . $obj->repository->name . "/docs/" . strtolower( $_page->page_name ) ),
        // "guid" => site_url( "/docs/" . $obj->repository->name . '/'.  strtolower( $_page->page_name ) ),
        "_id" => false
      );

      $_check = get_posts( array(
        "post_type" => "github_wiki_doc",
        "post_status" => "any",
        "name" => $_wiki[ 'post_name' ],
        'tax_query' => array(
          array(
            'taxonomy' => "github_wiki_doc_taxonomy",
            'field' => 'slug',
            'terms' => array( $_wiki[ '_product' ] ),
            'operator' => 'IN'
          )
        )
      ) );

      if( is_array( $_check ) && count( $_check ) && $_check[ 0 ] && $_check[ 0 ]->ID ) {
        $_wiki[ "_id" ] = $_check[ 0 ]->ID;
      }


      $_remote_get = wp_remote_get( $_wiki[ '_url' ], array(
        'headers' => array( 'Authorization' => 'token ' . get_option( 'wp-github-wiki/access_token' ) .  ' ', 'cache-control' => 'no-cache' )
      ) );

      // Could not fetch and does not exist, skip.
      if( wp_remote_retrieve_response_code( $_remote_get  ) !== 200 && !$_wiki[ "_id" ] ) {
        $_result[$_index]["ok"] = false;
        $_result[$_index]["message"] = "Could not fetch, skipping.";
        continue;
      }

      $_wiki[ 'post_content' ] = wp_remote_retrieve_body( $_remote_get );

      $_insert_detail = array(
        'post_author' => isset( $user_id ) ? $user_id : 6375,
        'post_content' => format_wiki_content( $_wiki[ 'post_content' ]   ),
        'post_content_filtered' => $_wiki[ 'post_content' ],
        'post_name' => $_wiki[ 'post_name' ],
        'post_title' => $_wiki[ 'post_title' ],
        'post_status' => 'publish',
        'post_type' => 'github_wiki_doc',
        'guid' => $_wiki[ 'guid' ],
        'tax_input' => array(
          'github_wiki_doc_taxonomy' => array( $_wiki[ '_product' ] )
        ),
        'meta_input' => array(
          // Used to find later.
          "wiki_path" => trailingslashit( "/" . join( "/", array( "product", $_wiki[ '_product' ], "docs", $_wiki[ 'post_name' ] ) ) ),
          "wiki_sha" => $_wiki[ '_sha' ],
          "wiki_name" => $_wiki[ '_name' ],
          "wiki_full_name" => $_wiki[ '_full_name' ],
          "wiki_product" => $_wiki[ '_product' ],
          "wiki_url" => $_wiki[ '_url' ]
        ),
        //'post_parent' => 0,
        //'menu_order' => 0,
        //'context' => '',
      );

      // We could not get it, trash it.
      if( wp_remote_retrieve_response_code( $_remote_get  ) !== 200 ) {
        $_insert_detail[ 'post_status' ] = "trash";
      }

      // Set the actual insert ID.
      if( $_wiki[ "_id" ] ) {
        $_insert_detail[ 'ID' ] = $_wiki[ "_id" ];
      }

      // Insert post.
      $_result[$_index][ "post_id" ] = wp_insert_post( $_insert_detail, true );

      // If all good, set terms.
      if( $_result[$_index][ "post_id" ] && !is_wp_error( $_result[$_index][ "post_id" ] ) ) {
        $_result[$_index][ "terms" ] = wp_set_object_terms( $_result[$_index][ "post_id" ], array( $_wiki[ '_product' ] ), 'github_wiki_doc_taxonomy' );
      }

    }

    wp_send_json(array(
      "ok" => true,
      "result" => $_result
    ));

  }

  /**
   * @param $content
   * @return string
   */
  function format_wiki_content( $content ) {

    if( !file_exists( __DIR__ . '/vendor/Parsedown.php' ) ) {
      return $content;
    }

    require_once( __DIR__ . '/vendor/Parsedown.php' );
    $Parsedown = new \Parsedown();

    return '<div class="wiki-content">' . $Parsedown->text($content) . '</div>';

  }

  class Actions {

    /**
     * Registery Post Type/ Taxonomy
     *
     */
    static public function init() {

      add_filter( 'post_link', 'UsabilityDynamics\GitHubWiki\Filters::github_wiki_doc_category_permalink', 1, 3 );
      add_filter( 'post_type_link', 'UsabilityDynamics\GitHubWiki\Filters::github_wiki_doc_category_permalink', 1, 3 );

      register_post_type( 'github_wiki_doc', array(
        'label' => __( 'Wiki', 'ud' ),
        'description' => __( 'Product documentation.', 'ud' ),
        'labels' => array(
          'name' => _x( 'Wikis', 'Post Type General Name', 'ud' ),
          'singular_name' => _x( 'Wiki', 'Post Type Singular Name', 'ud' ),
          'menu_name' => __( 'Wikis', 'ud' ),
          'name_admin_bar' => __( 'Wiki', 'ud' ),
          'archives' => __( 'Wiki Archives', 'ud' ),
          'parent_item_colon' => __( 'Parent Wiki:', 'ud' ),
          'all_items' => __( 'All Wikis', 'ud' ),
          'add_new_item' => __( 'Add New Wiki', 'ud' ),
          'add_new' => __( 'Add New', 'ud' ),
          'new_item' => __( 'New Wiki', 'ud' ),
          'edit_item' => __( 'Edit Wiki', 'ud' ),
          'update_item' => __( 'Update Wiki', 'ud' ),
          'view_item' => __( 'View Wiki', 'ud' ),
          'search_items' => __( 'Search Wiki', 'ud' ),
          'not_found' => __( 'Not found', 'ud' ),
          'not_found_in_trash' => __( 'Not found in Trash', 'ud' ),
          'featured_image' => __( 'Featured Image', 'ud' ),
          'set_featured_image' => __( 'Set featured image', 'ud' ),
          'remove_featured_image' => __( 'Remove featured image', 'ud' ),
          'use_featured_image' => __( 'Use as featured image', 'ud' ),
          'insert_into_item' => __( 'Insert into item', 'ud' ),
          'uploaded_to_this_item' => __( 'Uploaded to this item', 'ud' ),
          'items_list' => __( 'Wikis list', 'ud' ),
          'items_list_navigation' => __( 'Wikis list navigation', 'ud' ),
          'filter_items_list' => __( 'Filter items list', 'ud' ),
        ),
        'supports' => array( 'title', 'page-attributes' ),
        'taxonomies' => array( 'github_wiki_doc_taxonomy' ),
        'hierarchical' => false,
        'public' => true,
        //'query_var' => 'github_wiki_doc',
        'show_ui' => true,
        'show_in_menu' => true,
        'menu_position' => 15,
        'menu_icon' => 'dashicons-book',
        'show_in_admin_bar' => true,
        'show_in_nav_menus' => true,
        'can_export' => true,
        'has_archive' => false,
        'exclude_from_search' => true,
        'publicly_queryable' => true,
        'rewrite' => array(
          'slug' => 'product/%github_wiki_doc_taxonomy%/docs',
          'with_front' => false,
          'pages' => true,
          'feeds' => false,
        ),
        'capability_type' => 'page',
      ) );

      register_taxonomy( 'github_wiki_doc_taxonomy', array( 'github_wiki_doc' ), array(
        'labels' => array(
          'name' => _x( 'Wiki Categories', 'Taxonomy General Name', 'rdc' ),
          'singular_name' => _x( 'Wiki Category', 'Taxonomy Singular Name', 'rdc' ),
          'menu_name' => __( 'Products', 'rdc' ),
          'all_items' => __( 'All Wiki Categories', 'rdc' ),
          'parent_item' => __( 'Parent Wiki Category', 'rdc' ),
          'parent_item_colon' => __( 'Parent Wiki Category:', 'rdc' ),
          'new_item_name' => __( 'New Wiki Category Name', 'rdc' ),
          'add_new_item' => __( 'Add New Wiki Category', 'rdc' ),
          'edit_item' => __( 'Edit Wiki Category', 'rdc' ),
          'update_item' => __( 'Update Wiki Category', 'rdc' ),
          'view_item' => __( 'View Wiki Category', 'rdc' ),
          'separate_items_with_commas' => __( 'Separate items with commas', 'rdc' ),
          'add_or_remove_items' => __( 'Add or remove items', 'rdc' ),
          'choose_from_most_used' => __( 'Choose from the most used', 'rdc' ),
          'popular_items' => __( 'Popular Wiki Categories', 'rdc' ),
          'search_items' => __( 'Search Wiki Categories', 'rdc' ),
          'not_found' => __( 'Not Found', 'rdc' ),
          'no_terms' => __( 'No items', 'rdc' ),
          'items_list' => __( 'Wiki Categories list', 'rdc' ),
          'items_list_navigation' => __( 'Wiki Categories list navigation', 'rdc' ),
        ),
        'hierarchical' => false,
        'public' => false,
        'query_var' => 'github_wiki_doc_taxonomy',
        'rewrite' => array(
          'slug' => 'github_wiki_doc_taxonomy',
          'with_front' => false,
          'hierarchical' => false
        ),
        'show_ui' => false,
        'show_admin_column' => false,
        'show_in_nav_menus' => false,
        'show_tagcloud' => false,
      ) );


    }

    /**
     * Modify WC Product object with doc fields.
     *
     * @param $_this_post
     */
    static public function the_post($_this_post) {
      global $_wikis_found;

      // Inject into main WC product.
      if( $_this_post->post_type === 'github_wiki_doc' ) {
        //add_filter( 'the_content', 'UsabilityDynamics\GitHubWiki\Filters::the_content' );
        //add_filter( 'the_title', 'UsabilityDynamics\GitHubWiki\Filters::the_title' );
        //$_this_post->post_title = $_wikis_found[0]->post_title;
        $_this_post->post_content = format_wiki_content( $_this_post->post_content );
      }

      //die( '<pre>' . print_r( $_this_post, true ) . '</pre>' );
    }

  }

  class Filters {

    /**
     *
     * @author potanin@UD
     * @param $permalink
     * @param $post
     * @param $leavename
     * @return mixed
     */
    static public function github_wiki_doc_category_permalink( $permalink, $post, $leavename  ) {

      if( strpos( $permalink, '%github_wiki_doc_taxonomy%' ) === FALSE ) return $permalink;

      if( !$post || !is_object( $post ) ) {
        return $permalink;
      }

      $terms = wp_get_object_terms( $post->ID, 'github_wiki_doc_taxonomy', array(
        "fields" => "names"
      ) );

      if( !$terms || !$terms[0] ) {
        return $permalink;
      }

      return str_replace( '%github_wiki_doc_taxonomy%', $terms[0], $permalink );

    }

    static public function the_content( $original_content ) {
      return format_wiki_content( $original_content );
    }

    static public function the_title( $original_title ) {
      global $_wikis_found;

      if( is_array( $_wikis_found ) &&  count( $_wikis_found ) && $_wikis_found[0] && $_wikis_found[0]->ID ) {

        // remove filter
        remove_filter( 'the_title', 'UsabilityDynamics\GitHubWiki\Filters::the_title' );

        return $_wikis_found[0]->post_title;
      }

      return $original_title;
    }

  }

}