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
 *
 * ### Filters
 * 
 * * wp-github-wiki/post_type
 * * wp-github-wiki/api_path
 * 
 * 
 * https://api.usabilitydynamics.com/v1/product/update/github
 * 
 * https://www.usabilitydynamics.com/wp-admin/admin-ajax.php?action=/v1/product/update/github
 * https://usabilitydynamics-com-andypotanin.c9users.io/wp-admin/admin-ajax.php?action=/v1/product/update/github
 * /v1/product/update/github
 * 
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
  add_action( 'before_delete_post', 'UsabilityDynamics\GitHubWiki\Actions::before_delete_post', 20 );
  //add_action( 'the_post', 'UsabilityDynamics\GitHubWiki\Actions::the_post', 5 );

  /**
   * http://b49b413d4ffd.c.wpcloud.io/wp-admin/admin-ajax.php?action=/v1/github/wiki
   *
   *
   * WC products store their "full_name" in software_title meta key.
   *
   * wp post  list --post_type=github_wiki_doc --fields=ID --format=csv
   *
   * @todo Use "wiki_content_hash" meta field to identify renamed articles. Note: when an article is renamed it says "edited" in "action". If article says "edited" but not found on our system, try to match it by looking up wiki_content_hash.
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
        "page_name" => $_page->page_name,
        "product" => $obj->repository->full_name
      );

      // For now we skip Sidebar, in future we'll use it to set order and structure.

      // Build Wiki Object.
      // _uid - wp-crm-wp-crm-_sidebar

      if( isset( $_page->html_url ) ) {
        $_parsed = parse_url( $_page->html_url );
        $_url = 'https://raw.githubusercontent.com/wiki' . str_replace( '/wiki/', '/', $_parsed['path'] ) . '.md';
      }  else {
        $_url = 'https://raw.githubusercontent.com/wiki/' . $obj->repository->full_name . '/' . ( $_page->url_safe_file_name ? $_page->url_safe_file_name : $_page->page_name . '.md' );
      }

      $_wiki = array(
        "_name" => $_page->page_name,
        "_sha" => $_page->sha,
        "_action" => $_page->action,
        "_html_url" => $_page->html_url,
        "_product" => $obj->repository->name,
        "_full_name" => $obj->repository->full_name,
        "_uid" => sanitize_title( str_replace( '/', '-', $obj->repository->full_name ) . '-' . $_page->page_name ),
        "_owner" => $obj->repository->owner->login,
        "_url" => $_url,
        "_author" => $obj->sender->login,
        "post_name" => strtolower( $_page->page_name ),
        "post_title" => $_page->title,
        "post_content" => "",
        "guid" => site_url( "/product/" . $obj->repository->name . "/docs/" . strtolower( $_page->page_name ) ),
        "_id" => false
      );

      // Fix stupid removed dashes.
      $_wiki[ 'post_title' ] = str_replace( 'WP Property', 'WP-Property', $_wiki[ 'post_title' ] );
      $_wiki[ 'post_title' ] = str_replace( 'WP CRM', 'WP-CRM', $_wiki[ 'post_title' ] );
      $_wiki[ 'post_title' ] = str_replace( 'WP Invoice', 'WP-Invoice', $_wiki[ 'post_title' ] );

      // Check for a doc with same name in the given _product taxonomy (e.g. wp-madison)
      $_product = (array) end( get_posts( array(
        "post_type" => "product",
        "post_status" => "any",
        "meta_key" => 'software_title',
        "meta_value" => $_wiki[ '_full_name' ]
      ) ) );

      if( !is_array( $_product ) || !$_product ) {}

      $_remote_get = wp_remote_get( $_wiki[ '_url' ] . '?_cache=' . time(), array(
        'headers' => array( 'Authorization' => 'token ' . get_option( 'wp-github-wiki/access_token' ) .  ' ', 'cache-control' => 'no-cache' )
      ) );

      // Could not fetch and does not exist, skip.
      if( wp_remote_retrieve_response_code( $_remote_get  ) !== 200 && !$_wiki[ "_id" ] ) {
        $_result[$_index]["ok"] = false;
        $_result[$_index]["message"] = "Could not fetch, at [" . $_wiki[ '_url' ] . "] skipping.";
        log( "error", "Could not fetch, at [" . $_wiki[ '_url' ] . "] skipping." );
        continue;
      }

      $_wiki[ 'post_content' ] = wp_remote_retrieve_body( $_remote_get );

      // Check for a doc with same name in the given _product taxonomy (e.g. wp-madison)
      $_check = get_posts( $_check_query = array(
        "post_type" => "github_wiki_doc",
        "post_status" => "any",
        "meta_key" => "wiki_uid",
        "meta_value" => $_wiki[ '_uid' ]
      ));

      if( !empty( $_check ) && isset( $_check[ 0 ] ) && isset( $_check[ 0 ]->ID ) ) {
        log( 'info', "Wiki [" . $_page->page_name . "] found by using _uid [" . $_check[ 0 ]->ID . "] ID of the first."  );
      } else {
        log( 'info', "Wiki [" . $_page->page_name . "] not found by using _uid [" . $_wiki[ '_uid' ] . "]."  );
      }

      // Try to find by content sha if "created".
      if( !$_check && $_page->action === 'edited' ) {

        $_check = get_posts( $_check_query = array(
          "post_type" => "github_wiki_doc",
          "post_status" => "any",
          "meta_key" => "wiki_content_hash",
          "meta_value" => md5($_wiki[ 'post_content' ])
        ));

        if( !empty( $_check ) && isset( $_check[ 0 ] ) && isset( $_check[ 0 ]->ID ) ) {
          log( 'info', "Wiki [" . $_page->page_name . "] not found by using _uid, performed wiki_content_hash [" . $_check_query[ 'meta_value' ] . "] and got [" . count( $_check ) . "] results with [" . ( !empty($_check) ? $_check[ 0 ]->ID : 'n/a' ). "] ID of the first. Old uid was [" . get_post_meta( $_check[ 0 ]->ID, 'wiki_uid', true ) .  "]."  );
        } else {
          log( 'error', "Wiki [" . $_page->page_name . "] not found by using _uid, not by wiki_content_hash [" . $_check_query[ 'meta_value' ] . "]."  );
        }

      }

      if( is_array( $_check ) && count( $_check ) && $_check[ 0 ] && $_check[ 0 ]->ID ) {
        $_wiki[ "_id" ] = $_check[ 0 ]->ID;
      }

      $_insert_detail = array(
        'post_author' => isset( $user_id ) ? $user_id : 6375,
        'post_content' => null,
        'post_content_filtered' => $_wiki[ 'post_content' ],
        'post_name' => str_replace( '%3f', '', $_wiki[ '_uid' ] ),
        'post_title' => $_wiki[ 'post_title' ],
        'post_status' => 'publish',
        'post_type' => 'github_wiki_doc',
        'guid' => str_replace( '%3f', '', $_wiki[ 'guid' ] ),
        'tax_input' => array(
          'github_wiki_doc_taxonomy' => array( $_wiki[ '_product' ] ),
          'github_wiki_org_taxonomy' => array( $_wiki[ '_owner' ] )
        ),
        'meta_input' => array(
          // Used to find later.
          "wiki_uid" => str_replace( '%3f', '', $_wiki[ '_uid' ] ),
          "wiki_path" =>  trailingslashit( str_replace( '%3f', '', ( trailingslashit( "/" . join( "/", array( "product", $_product[ 'post_name' ], "docs", sanitize_title( $_wiki[ 'post_name' ] )  ) ) ) ) ) ),
          "wiki_sha" => $_wiki[ '_sha' ],
          "wiki_name" => str_replace( '%3f', '', sanitize_title( $_wiki[ '_name' ] ) ),
          "wiki_title" => $_wiki[ 'post_title' ],
          "wiki_product_slug" => $_product[ 'post_name' ],
          "wiki_full_name" => $_wiki[ '_full_name' ],
          "wiki_product" => $_wiki[ '_product' ],
          "wiki_product_title" => $_product['post_title'],
          "wiki_owner" => $_wiki[ '_owner' ],
          "wiki_url" => $_wiki[ '_url' ],
          'wiki_file' => strtolower( $_wiki[ '_name' ] ) . '.md',
          "wiki_type" => "content",
          "wiki_content_hash" => md5( $_wiki[ 'post_content' ])
        ),
        //'post_parent' => 0,
        //'menu_order' => 0,
        //'context' => '',
      );

      //die( '<pre>' . print_r( $_insert_detail, true ) . '</pre>' );
      if( wp_remote_retrieve_response_code( $_remote_get  ) !== 200 ) {
        $_insert_detail[ 'post_status' ] = "trash";
      }

      // Set the actual insert ID.
      if( $_wiki[ "_id" ] ) {
        $_insert_detail[ 'ID' ] = $_wiki[ "_id" ];
      }

      // Format post_content now that we have all the post detail.
      if( strtolower( $_page->page_name ) === '_sidebar' || strtolower( $_page->page_name ) === '_footer' ) {

        if( $_computed_sidebar = compute_sidebar_content($_wiki[ 'post_content' ], $_product) ) {
          update_post_meta( $_product['ID' ], '_api_wiki_sidebar', $_computed_sidebar );
          update_post_meta( $_product['ID' ], '_api_wiki', count( $_computed_sidebar['data'] ) );
          log( 'info', "Added computed sidebar results to [_api_wiki_sidebar] to [" . $_product[ 'ID' ] . "] product meta with [" . count( $_computed_sidebar['data'] ) . "] items." );
        } else {
          delete_post_meta( $_product['ID' ], '_api_wiki_sidebar' );
          delete_post_meta( $_product['ID' ], '_api_wiki' );
          log( 'error', "Failed to compute sidebar for [" . $_wiki[ '_product' ] . "]." );
        }

        $_result[$_index] = array(
          "ok" => true,
          "message" => "Sidebar computed for [".$_wiki[ '_product' ]."] , [" .  '_api_wiki_sidebar' . $_wiki[ '_product' ].  "] updated.",
          "items" => count( $_computed_sidebar['data'] )
        );

      } else {
        $_insert_detail[ 'post_content' ] = format_wiki_content( $_wiki[ 'post_content' ], $_insert_detail, $_product );

        // Insert post.
        $_result[$_index][ "post_id" ] = wp_insert_post( $_insert_detail, true );

        if( $_result[$_index][ "post_id" ] && !is_wp_error( $_result[$_index][ "post_id" ] )  ) {

          if( $_wiki[ "_id" ] === $_result[$_index][ "post_id" ] ) {
            log( 'info', "Wiki [" . $_page->page_name . "] updated in post_id [" . $_result[$_index][ "post_id" ] . "] with _uid of [" . $_wiki[ '_uid' ] . "]"  );
          } else {
            log( 'info', "Wiki [" . $_page->page_name . "] created as post_id [" . $_result[$_index][ "post_id" ] . "] with _uid of [" . $_wiki[ '_uid' ] . "]"  );
          }


          $_body = array(
            "answer_html"=> $_insert_detail['post_content'],
            "topic_name" => $_product[ 'post_title' ],
            'published' => $_SERVER['GIT_BRANCH'] !== 'production' ? false : true
          );

          $add_prefix = ( strpos( strtolower( str_replace( array( ':' ), '', $_insert_detail[ 'post_title' ] ) ), strtolower( str_replace( array( ':' ), '', $_product['post_title'] ) ) ) > -1 ) ? false : true;

          // Publish only if the product title exists in the article/post title.
          if( $add_prefix  ) {
            $_body['question'] = $_product['post_title'] . ' - ' . $_insert_detail[ 'post_title' ];
          } else{
            $_body['question'] = $_insert_detail[ 'post_title' ];
          }

          $_result[$_index][ "ok" ] = true;
          $_result[$_index][ "permalink" ] = get_the_permalink( $_result[$_index][ "post_id" ]  );
          $_result[$_index][ "post_title" ] = get_post_field( 'post_title',  $_result[$_index][ "post_id" ]  );

          $_result[$_index][ "terms" ] = array(
            wp_set_object_terms( $_result[$_index][ "post_id" ], array( $_wiki[ '_product' ] ), 'github_wiki_doc_taxonomy' ),
            wp_set_object_terms( $_result[$_index][ "post_id" ], array( $_wiki[ '_owner' ] ), 'github_wiki_org_taxonomy' )
          );

        } else {
          log( 'error', "Wiki [" . $_page->page_name . "] could NOT be inserted." );
          log( 'error', $_result[$_index][ "post_id" ] );
        }

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
  function format_wiki_content( $content, $_post = null, $_product = null ) {

    if( !file_exists( __DIR__ . '/vendor/Parsedown.php' ) ) {
      return $content;
    }

    require_once( __DIR__ . '/vendor/Parsedown.php' );

    $Parsedown = new \Parsedown();

    $_formatted = $Parsedown->text($content);

    // Replace "https://github.com/wp-property/wp-property-power-tools/wiki/Overview" with "/products/wp-property-power-tools/docs/Overview"
    preg_match('(github\.com.+?\/wiki)', $_formatted, $matches, PREG_OFFSET_CAPTURE, 3);

    foreach( $matches as $_match ) {

      $_url = $_match[0];

      $_parts = explode('/', $_url );

      $_product_family = $_parts[1]; // e.g. "wp-property"
      $_product_name = $_parts[2]; // e.g. "wp-property-power-tools"

      $_better_url = '/product' . str_replace( 'github.com/' . $_product_family, '', $_url );
      $_better_url = str_replace( '/wiki', '/docs', $_better_url );

      $_formatted = str_replace( $_url, $_better_url, $_formatted );

      // make links relative
      $_formatted = str_replace( 'https:///', '/', $_formatted );

    }

    //die($_formatted);
    return '<div class="wiki-content">' . $_formatted . '</div>';

  }

  /**
   * Smart Sidebar Processing.
   *
   * Adds:
   *
   * - wiki_sidebar_title
   * - wiki_sidebar_section
   *
   * @param $content
   * @param $_post
   * @return string
   */
  function compute_sidebar_content( $content, $_product = null ) {

    $lines = explode("\n", $content);

    $parsed = array();

    $result = array();

    $sections = array();

    // Get the relative base_url with produc url
    if( isset( $_product ) && $_product['ID'] ) {
      $_permalink = get_the_permalink( $_product['ID' ] );
      $_parsed = parse_url($_permalink );
      $_base_url = $_parsed['path'];
    }

    foreach( $lines as $index => $line ) {

      // skip the image url
      if( strpos( $line, 'media.usabilitydynamics.com'  ) > 0 ) {
        continue;
      }

      // Skip blank lines, but first unset the current section.
      if( !$line || empty( $line ) ) {
        $current_section = '';
        continue;
      }

      // Section title.
      if( strpos( $line, '###' ) === 0 ) {
        $sections[] = $current_section = trim( str_replace( '###', '', $line ) );
        $order = 0;
        continue;
      }

      // Link line.
      if( strpos( $line, '*' ) >= 0 ) {

        preg_match_all('/\[(.+?)\]\(([^"]+)(?: \"([^\"]+)\")?\)/m', $line, $matches);

        if( $matches[0] ) {

          $_entry = array(
            "section" => isset( $current_section ) ? $current_section : '',
            "raw" => end( $matches[ 0 ] ),
            "title" => end( $matches[ 1 ] ),
            "slug" => strtolower( end( $matches[ 2 ] ) ),
            "wiki_path" =>  trailingslashit( ( isset( $_base_url ) ? $_base_url : '' ) . '/docs/' . str_replace( '%3f', '', sanitize_title( strtolower( end( $matches[ 2 ] ) ) ) ) ),
            "order" => isset( $order ) ? $order++ : 0,
            "product_id" => $_product['ID' ],
            "product_title" => get_post_meta($_product['ID'], 'software_title', true ),
            "index" => $index
          );

          if( $_actual_wiki_page = end(get_posts( $_wiki_query = array(
            "post_type" => "github_wiki_doc",
            "post_status" => "publish",
            'meta_key' => "wiki_path",
            "meta_value" => $_entry[ 'wiki_path' ]
          ) )) ) {
            $_entry[ 'post_id' ] = $_actual_wiki_page->ID;
            $_entry[ 'post_title' ] = $_actual_wiki_page->post_title;
            update_post_meta( $_entry[ 'post_id' ], 'wiki_sidebar_title', $_entry['title'] );
            update_post_meta( $_entry[ 'post_id' ], 'wiki_sidebar_section', $_entry['section'] );
          }

          if( !$_entry[ 'post_id' ] ) {
            log( 'error', 'Did not find real post for [' . $_entry[ 'wiki_path' ] . '] entry ['.$_entry['raw'].'] in sidebar.' );
            // log( 'error', $_entry );
          }

          $result[] = $_entry;

          continue;
        }

      }

      // if line appears ot have a link..
      if( strpos( $line, '[[' ) ){

        $raw_line = $line;

        // extract just the text
        $_title = str_replace( array( '* [[', ']]' ), '', $line  );

        $_entry = array(
          "section" => isset( $current_section ) ? $current_section : '',
          "raw" => str_replace( '* ', '', $raw_line ),
          "title" => $_title,
          "slug" => sanitize_title( strtolower( $raw_line ) ),
          "wiki_path" =>  trailingslashit(( isset( $_base_url ) ? $_base_url : '' ) . '/docs/' . str_replace( '%3f', '', sanitize_title( strtolower( $raw_line ) ) ) ),
          "order" => isset( $order ) ? $order++ : 0,
          "product_id" => $_product['ID' ],
          "product_title" => get_post_meta($_product['ID'], 'software_title', true ),
          "index" => $index
        );

        if( $_actual_wiki_page = end(get_posts( $_wiki_query = array(
          "post_type" => "github_wiki_doc",
          "post_status" => "publish",
          'meta_key' => "wiki_path",
          "meta_value" => $_entry[ 'wiki_path' ]
        ) )) ) {
          $_entry[ 'post_id' ] = $_actual_wiki_page->ID;
          $_entry[ 'post_title' ] = $_actual_wiki_page->post_title;
          update_post_meta( $_entry[ 'post_id' ], 'wiki_sidebar_title', $_entry['title'] );
          update_post_meta( $_entry[ 'post_id' ], 'wiki_sidebar_section', $_entry['section'] );
        }

        if( !$_entry[ 'post_id' ] ) {
          log( 'error', 'Did not find real post for [' . $_entry[ 'wiki_path' ] . '] entry ['.$_entry['raw'].'] in sidebar.' );
          // log( 'error', $_entry );
        }

        $result[] = $_entry;

      }

    }

    return array( "data" => $result, 'sections' => $sections, "last_update" => time() );

  }

	/**
	 * Legacy/Raw Markdown Parsing.
	 *
	 * @param $content
	 * @param null $_product
	 *
	 * @return string
	 * @internal param $_post
	 */
  function format_sidebar_content( $content, $_product = null ) {

    if( !file_exists( __DIR__ . '/vendor/Parsedown.php' ) ) {
      return $content;
    }

    require_once( __DIR__ . '/vendor/Parsedown.php' );

    $Parsedown = new \Parsedown();

    $lines = explode("\n", $content);

    $parsed = array();

    // Get the relative base_url with produc url
    if( isset( $_product ) && $_product['ID'] ) {
      $_permalink = get_the_permalink( $_product['ID' ] );
      $_parsed = parse_url($_permalink );
      $_base_url = $_parsed['path'];
    }

    foreach( $lines as $line ) {

      // skip the image url
      if( strpos( $line, 'media.usabilitydynamics.com'  ) > 0 ) {
        continue;
      }

      // if line appears ot have a link..
      if( strpos( $line, '[[' ) ) {

        // extract just the text
        $_text = str_replace( array( '* [[', ']]' ), '', $line  );

        // sanitize what we think is the url...
        if( isset( $_base_url ) ) {
          $line = trim($line). '(' . $_base_url . '/docs/' . sanitize_title( str_replace( ' ', '-', $_text )  ) . ')';
        } else {
          $line = trim($line). '(' . sanitize_title( str_replace( ' ', '-', $_text )  ) . ')';
        }

        $line = str_replace( '[[', '[', $line );
        $line = str_replace( ']]', ']', $line );

      }

      $parsed[] = $line;
    }

    $_content = '' . $Parsedown->text(join("\n",$parsed)) . '';

    return $_content;

  }

  /**
   * Write to log.
   *
   * tail -F wp-content/github-wiki*
   *
   * @param $data
   */
  function log($type, $data){

    //$entry = "=== " . date("F j, Y, g:i a") . " ===\n" . print_r( $data , true ) . "\n";
    $entry = "" . date("F j, Y, g:i a") . " - " . print_r( $data , true ) . "\n";

    file_put_contents('/var/www/wp-content/github-wiki.' . $type . '.log', $entry, FILE_APPEND );

  }

  class Actions {

    /**
     * Registery Post Type/ Taxonomy
     *
     */
    static public function init() {
      global $wp_taxonomies;

      // https://usabilitydynamics.com/wp-admin/?test-wiki=sidebar
      if( isset( $_GET['test-wiki'] ) &&  $_GET['test-wiki'] === 'sidebar' ) {
        die(format_sidebar_content(file_get_contents('/var/www/wp-content/static/tests/fixtures/github-widebar.md'), array( "ID" => "218619" )));
      }

      add_filter( 'post_link', 'UsabilityDynamics\GitHubWiki\Filters::github_wiki_doc_category_permalink', 1, 3 );
      add_filter( 'post_type_link', 'UsabilityDynamics\GitHubWiki\Filters::github_wiki_doc_category_permalink', 1, 3 );

      $_wiki_post_type = array(
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
        'taxonomies' => array( 'github_wiki_doc_taxonomy', 'github_wiki_org_taxonomy' ),
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
      ) ;
      
      register_post_type( 'github_wiki_doc', apply_filters( 'wp-github-wiki/post_type', $_wiki_post_type ) );

      register_taxonomy( 'github_wiki_doc_taxonomy', array( 'github_wiki_doc' ), array(
        'labels' => array(
          'name' => _x( 'Wiki Products Categories', 'Taxonomy General Name', 'wp-github-wiki' ),
          'singular_name' => _x( 'Wiki Products Category', 'Taxonomy Singular Name', 'wp-github-wiki' ),
          'menu_name' => __( 'Products', 'wp-github-wiki' ),
          'all_items' => __( 'All Wiki Categories', 'wp-github-wiki' ),
          'parent_item' => __( 'Parent Wiki Category', 'wp-github-wiki' ),
          'parent_item_colon' => __( 'Parent Wiki Category:', 'wp-github-wiki' ),
          'new_item_name' => __( 'New Wiki Category Name', 'wp-github-wiki' ),
          'add_new_item' => __( 'Add New Wiki Category', 'wp-github-wiki' ),
          'edit_item' => __( 'Edit Wiki Category', 'wp-github-wiki' ),
          'update_item' => __( 'Update Wiki Category', 'wp-github-wiki' ),
          'view_item' => __( 'View Wiki Category', 'wp-github-wiki' ),
          'separate_items_with_commas' => __( 'Separate items with commas', 'wp-github-wiki' ),
          'add_or_remove_items' => __( 'Add or remove items', 'wp-github-wiki' ),
          'choose_from_most_used' => __( 'Choose from the most used', 'wp-github-wiki' ),
          'popular_items' => __( 'Popular Wiki Categories', 'wp-github-wiki' ),
          'search_items' => __( 'Search Wiki Categories', 'wp-github-wiki' ),
          'not_found' => __( 'Not Found', 'wp-github-wiki' ),
          'no_terms' => __( 'No items', 'wp-github-wiki' ),
          'items_list' => __( 'Wiki Categories list', 'wp-github-wiki' ),
          'items_list_navigation' => __( 'Wiki Categories list navigation', 'wp-github-wiki' ),
        ),
        'hierarchical' => false,
        'public' => false,
        'query_var' => 'github_wiki_doc_taxonomy',
        'rewrite' => array(
          'slug' => 'github_wiki_doc_taxonomy',
          'with_front' => false,
          'hierarchical' => false
        ),
        'show_ui' => true,
        'show_in_menu' => true,
        'show_admin_column' => false,
        'show_in_nav_menus' => false,
        'show_tagcloud' => false,
      ) );

      register_taxonomy( 'github_wiki_org_taxonomy', array( 'github_wiki_doc' ), array(
        'labels' => array(
          'name' => _x( 'Wiki Organization Categories', 'Taxonomy General Name', 'wp-github-wiki' ),
          'singular_name' => _x( 'Wiki Organization Category', 'Taxonomy Singular Name', 'wp-github-wiki' ),
          'menu_name' => __( 'Organizations', 'wp-github-wiki' ),
          'all_items' => __( 'All Wiki Categories', 'wp-github-wiki' ),
          'parent_item' => __( 'Parent Wiki Category', 'wp-github-wiki' ),
          'parent_item_colon' => __( 'Parent Wiki Category:', 'wp-github-wiki' ),
          'new_item_name' => __( 'New Wiki Category Name', 'wp-github-wiki' ),
          'add_new_item' => __( 'Add New Wiki Category', 'wp-github-wiki' ),
          'edit_item' => __( 'Edit Wiki Category', 'wp-github-wiki' ),
          'update_item' => __( 'Update Wiki Category', 'wp-github-wiki' ),
          'view_item' => __( 'View Wiki Category', 'wp-github-wiki' ),
          'separate_items_with_commas' => __( 'Separate items with commas', 'wp-github-wiki' ),
          'add_or_remove_items' => __( 'Add or remove items', 'wp-github-wiki' ),
          'choose_from_most_used' => __( 'Choose from the most used', 'wp-github-wiki' ),
          'popular_items' => __( 'Popular Wiki Categories', 'wp-github-wiki' ),
          'search_items' => __( 'Search Wiki Categories', 'wp-github-wiki' ),
          'not_found' => __( 'Not Found', 'wp-github-wiki' ),
          'no_terms' => __( 'No items', 'wp-github-wiki' ),
          'items_list' => __( 'Wiki Categories list', 'wp-github-wiki' ),
          'items_list_navigation' => __( 'Wiki Categories list navigation', 'wp-github-wiki' ),
        ),
        'hierarchical' => false,
        'public' => false,
        'query_var' => 'github_wiki_org_taxonomy',
        'rewrite' => array(
          'slug' => 'github_wiki_org_taxonomy',
          'with_front' => false,
          'hierarchical' => false
        ),
        'show_ui' => true,
        'show_in_menu' => true,
        'show_admin_column' => false,
        'show_in_nav_menus' => false,
        'show_tagcloud' => false,
      ) );
      
      
      // die( '<pre>' . print_r( $wp_taxonomies, true ) . '</pre>' );
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
        //add_filter( 'the_title', 'UsabilityDynamics\GitHubWiki\Filters::the_title' );
        //$_this_post->post_title = $_wikis_found[0]->post_title;
        $_this_post->post_content = format_wiki_content( $_this_post->post_content, $_this_post );
      }

    }

    static public function before_delete_post( $post_id ) {

      $_post = get_post($post_id);

      if( $_post->post_type !== 'github_wiki_doc' ) {
        return;
      }


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

      //$_wiki_path = get_post_meta( $post->ID, 'wiki_path', true );
      $wiki_product_slug = get_post_meta( $post->ID, 'wiki_product_slug', true );
      $wiki_uid  = get_post_meta( $post->ID, 'wiki_uid', true );
      $wiki_name  = sanitize_title( get_post_meta( $post->ID, 'wiki_name', true ) );

      // replae 'wp-property-wp-property-responsive-slideshow-home" with just "home"
      $permalink = str_replace( $wiki_uid,  $wiki_name, $permalink );

      return str_replace( '%github_wiki_doc_taxonomy%', $wiki_product_slug, $permalink );

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
