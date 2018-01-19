<?php

namespace Tanios\ContentfulWp;

class Plugin
{

    /**
     * @var Plugin Instance of plugin
     */
    private static $instance;

    /**
     * @var string Path of the plugin's directory
     */
    private $path;

    /**
     * @var string URI of the plugin's directory
     */
    private $uri;

    /**
     * @var string ROOT path
     */
    private $root;

    /**
     * @var string Version of the plugin
     */
    private $version = "0.0.2";

    /**
     * @var API API instance
     */
    private $api;

    /**
     * Textdomain of the plugin
     */
    const TEXTDOMAIN = "contentful-wp";

    /**
     * Prefix used for database
     */
    const PREFIX = "contentful_wp_";


    /**
     * Error messages
     */

    const ERROR_CANNOT_RETRIEVE_ACCESS_TOKEN = "error_access_token";

    /**
     * @param $root string Path to the root directory of plugin. Usually the caller file's __FILE__ constant.
     */
    public function __construct($root)
    {

        //setting up the instance
        self::$instance = $this;

        //setting up the path and uri
        $this->root = $root;
        $this->path = plugin_dir_path($root);
        $this->uri = plugin_dir_url($root);

        //if there is an access token
        if( $access_token = get_option( 'contentful_access_token', false ) )
        {
            //creating an API instance
            $this->api = new API( $access_token );
        }

        //registering actions
        $this->addActions();

        //registering filters
        $this->addFilters();

    }

    /**
     * Adds actions (hooks)
     */
    private function addActions()
    {

        //rewrite tag for query
        add_action( 'init', array( $this, 'rewrite_tag' ) );

        //loading text domain
        add_action( 'plugins_loaded', array( $this, '_actionLoadTextDomain' ) );

        //admin menu
        add_action( 'admin_menu', array( $this, '_actionAdminMenu' ) );

        //connect api
        add_action( 'wp_footer', array( $this, '_actionConnect' ) );

        //on post updated
        add_action( 'save_post', array( $this, '_actionPostUpdated' ), 99, 2 );

        //sync with contentful
        add_action( 'admin_init', array( $this, '_actionSync' ) );

    }

    public function rewrite()
    {

        //nice url for admin page
        add_rewrite_rule(
            '^contentful\-wp\-connect$',
            'index.php?contentful_wp_connect=1',
            'top'
        );

        //flush rewrite rules
        flush_rewrite_rules();

    }

    public function rewrite_tag()
    {

        //adding tag for rewriting rule
        add_rewrite_tag('%contentful_wp_connect%','([^/]*)');

    }

    private function addFilters()
    {



    }

    /**
     * Loads plugin's text domain
     */
    public function _actionLoadTextDomain()
    {

        //loading the plugin text domain
        load_plugin_textdomain( Plugin::TEXTDOMAIN, false, basename( dirname( $this->root ) ) . '/languages' );

    }

    public function _actionAdminMenu()
    {

        //adding menu
        add_menu_page(

            __( 'Contentful', Plugin::TEXTDOMAIN ),
            __( 'Contentful', Plugin::TEXTDOMAIN ),
            'manage_options',
            'contentful-wp',
            array( $this, 'options' ),
            $this->uri . 'assets/images/icon.png'

        );

    }

    public function options()
    {

        //error messages
        $messages = array();

        //checking for message
        if( ! empty( $_GET[ 'message' ] ) )
        {
            //processing messages
            $this->processMessages( $messages );
        }

        //capturing errors
        try
        {

            //redirect url
            $redirect_url = home_url( 'contentful-wp-connect' );

            //getting settings
            //API ACCESS
            $client_id = get_option( 'contentful_client_id', false ); //CLIENT ID
            $access_token = get_option( 'contentful_access_token', false ); //ACCESS TOKEN

            //IMPORTATION
            $imported = get_option( 'contentful_imported', false );
            $space = get_option( 'contentful_space', false );
            $spaces = array();
            $content_types = array();

            //if no client id
            if( ! $client_id )
            {
                //processing client id
                $this->processClientID( $redirect_url );
            }
            else
            {

                //verifying access token
                if( ! $access_token ) //not set
                {

                    //processing actions
                    $this->processAccessToken( $access_token );

                    //if still no access token
                    if( ! $access_token )
                    {
                        //removing client id
                        update_option( 'contentful_client_id', false );

                        //redirect to authentication
                        echo '<script type="text/javascript">document.location.href="' . admin_url( 'admin.php?page=contentful-wp&message=' . Plugin::ERROR_CANNOT_RETRIEVE_ACCESS_TOKEN ) . '";</script>';
                    }

                }
                else //set
                {
                    //adding message to say that the client is connected
                    $messages[ __( 'You are connected to your Contentful application.', Plugin::TEXTDOMAIN ) ] = true;

                    //Actions
                    $this->processActions();

                    //if not imported yet
                    if ( !$imported )
                    {

                        //processing importation
                        $this->processImportation( $space, $spaces, $content_types );

                    }
                }

            }

        }
        catch( \Exception $e )
        {
            //adding error to messages array
            $messages[ $e->getMessage() ] = false;
        }



        //displaying option page
        include_once $this->path . 'inc/templates/options.inc.php';

    }

    private function processMessages( &$messages )
    {

        //determining message
        switch( $_GET[ 'message' ] )
        {

            case Plugin::ERROR_CANNOT_RETRIEVE_ACCESS_TOKEN:

                $messages[ __( "An error occured while processing your access token. Please try again.", Plugin::TEXTDOMAIN ) ] = false;

            break;

        }

    }

    private function processClientID( $redirect_url )
    {

        //checking if form posted
        if( isset( $_POST ) && isset( $_POST[ 'contentful_connect_nonce' ] ) )
        {

            //verifying nonce
            if( ! wp_verify_nonce( $_POST[ 'contentful_connect_nonce' ], 'contentful_connect' ) )
            {
                //nonce invalid
                throw new \Exception( __( 'Nonce invalid. Cannot connect to your OAuth app.', Plugin::TEXTDOMAIN ) );
            }

            //nonce is valid and form posted

            //verifying client id
            if( empty( $_POST[ 'client_id' ] ) )
            {
                //client id not specified
                throw new \Exception( __( "You need to specify your app's client id.", Plugin::TEXTDOMAIN ) );
            }

            //if everything is valid, saving client id to database
            $client_id = esc_attr( $_POST[ 'client_id' ] );
            update_option( 'contentful_client_id', $client_id );

            //redirecting for OAuth authentification

            //oauth url
            $oauth_url = "https://be.contentful.com/oauth/authorize?response_type=token&client_id=$client_id&redirect_uri=$redirect_url&scope=content_management_manage";

            //redirecting
            echo '<script type="text/javascript">document.location.href = "' . $oauth_url . '";</script>';

            //don't show anything else
            return;

        }

    }

    private function processAccessToken( &$access_token )
    {

        //checking if action is set
        if( ! isset( $_GET[ 'action' ] ) )
        {
            return; //nothing to do here
        }

        //deterrmining action
        if( $_GET[ 'action' ] == 'save_access_token' )
        {

            //Saving access token

            //checking if access token has been set
            if( ! isset( $_GET[ 'access_token' ] ) || $_GET[ 'access_token' ] == -1 )
            {
                throw new \Exception( __( "An error occured while connecting to your Contentful application.", Plugin::TEXTDOMAIN ) );
            }

            //verifying nonce
            if( ! isset( $_GET[ 'nonce' ] ) || ! wp_verify_nonce( $_GET[ 'nonce' ], 'contentful_save_access_token' ) )
            {
                //deleting client id
                update_option( 'contentful_client_id', false );

                //showing an error message
                throw new \Exception( __( "Nonce invalid. Please try to connect again.", Plugin::TEXTDOMAIN ) );
            }

            //everything looks good, saving access token to database
            $access_token = esc_attr( $_GET[ 'access_token' ] );
            update_option( 'contentful_access_token', $access_token );

            //redirecting to next step
            echo '<script type="text/javascript">document.location.href = "' . admin_url( 'admin.php?page=contentful-wp' ) . '";</script>';

            //do not do anything else, waiting for redirection
            return;

        }

    }

    private function processImportation( &$space, &$spaces, &$content_types )
    {

        //getting spaces from API
        $spaces = $this->api->request( 'spaces', 'get' );

        //if no space saved and space selected
        if( ! empty( $_POST[ 'space' ] ) && isset( $_POST[ 'contentful_select_space_nonce' ] ) )
        {

            //verifying nonce
            if( ! wp_verify_nonce( $_POST[ 'contentful_select_space_nonce' ], 'contentful_select_space' ) )
            {
                //showing message
                throw new \Exception( __( 'Nonce invalid. Please try again.', Plugin::TEXTDOMAIN ) );
            }

            //saving space to database
            $space = $_POST[ 'space' ];
            update_option( 'contentful_space', $space );

        }

        //if a space has been selected
        if( $space )
        {

            //getting content types
            $content_types = $this->api->request( "spaces/$space/content_types", 'get' );

            if( isset( $_POST[ 'content_types' ] ) && isset( $_POST[ 'contentful_import_content_types_nonce' ] ) )
            {

                //verifying nonce
                if( ! wp_verify_nonce( $_POST[ 'contentful_import_content_types_nonce' ], 'contentful_import_content_types' ) )
                {
                    //throwing new exception
                    throw new \Exception( __( "Nonce invalid. Please try again.", Plugin::TEXTDOMAIN ) );
                }

                //if no content type selected
                if( ! is_array( $_POST[ 'content_types' ] ) || count( $_POST[ 'content_types' ] ) < 1 )
                {
                    //showing anj error message
                    throw new \Exception( __( 'You need to select atleast one content type to import.', Plugin::TEXTDOMAIN ) );
                }

                //generating content types
                $this->generateContentTypes( $content_types );

            }

        }
    }

    private function processActions()
    {

        //checking if action is set
        if( ! isset( $_GET[ 'action' ] ) )
        {
            return; //nothing to do here
        }

        switch( $_GET[ 'action' ] )
        {

            case "import_entries":

                //doing import
                define( 'DOING_CONTENTFUL_IMPORT', true );

                //verifiying nonce
                if ( !isset( $_GET[ 'nonce' ] ) || !wp_verify_nonce( $_GET[ 'nonce' ], 'contentful_import_entries' ) )
                {
                    //throwing an error
                    throw new \Exception( __( 'Nonce invalid. Please try again.', Plugin::TEXTDOMAIN ) );
                }

                ///checking if content types are defined
                if ( !isset( $_GET[ 'content_types' ] ) )
                {
                    //throwing an error
                    throw new \Exception(
                        __( 'No content type has been specified. Cannot import entries.', Plugin::TEXTDOMAIN )
                    );
                }

                //getting content types array
                $content_types = explode( ',', $_GET[ 'content_types' ] );

                //if no content types
                if ( count( $content_types ) < 1 )
                {
                    //throwing an error
                    throw new \Exception(
                        __( 'No content type has been specified. Cannot import entries.', Plugin::TEXTDOMAIN )
                    );
                }

                //getting space
                $space = get_option( 'contentful_space', false );

                //if no space defined
                if ( !$space )
                {
                    //throwing an error
                    throw new \Exception(
                        __( 'No space has been selected. Please select a space first.', Plugin::TEXTDOMAIN )
                    );
                }

                //getting imported content types
                $imported_content_types = get_option( 'contentful_content_types', array() );

                //if imported content types is not an array
                if( ! is_array( $imported_content_types ) )
                {
                    //decoding json
                    $imported_content_types = json_decode( $imported_content_types, true );
                }

                //fields temp array for later use
                $fields_temp = array();

                //getting entries of that space
                $total = false;
                $skip = 0;
                $limit = 100;
                while ( $total === false || $total > $skip + $limit )
                {
                    //getting entries
                    $entries = $this->api->request( "spaces/$space/entries", 'get' );

                    //getting total, limit and skip
                    $total = $entries->sys->total;
                    $limit = $entries->sys->limit;

                    //if another page
                    if ( $total > $skip + $limit )
                    {
                        //new page
                        $skip = $skip + $limit;
                    }

                    //for each entries
                    foreach ( $entries->items as $entry )
                    {

                        //checking that we need to import this entry
                        //if content type is not in the list, we skip
                        if ( ! isset( $imported_content_types[ $entry->sys->contentType->sys->id ] ) )
                        {
                            continue; //passing to the next one
                        }

                        //checking if already imported
                        $already = get_posts( array(
                            'post_type'         => $imported_content_types[ $entry->sys->contentType->sys->id ],
                            'posts_per_page'    => 1,
                            'fields'            => 'ids',
                            'meta_query'        => array(
                                array(
                                    'key'       => 'contentful_id',
                                    'value'     => $entry->sys->id,
                                    'compare'   => '='
                                )
                            ),
                        ) );

                        //preparing post information
                        $args = array(

                            'post_date'     => $entry->sys->createdAt,
                            'post_modified' => $entry->sys->updatedAt,
                            'post_type'     => $imported_content_types[ $entry->sys->contentType->sys->id ],
                            'post_status'   => 'publish',
                            'meta_input'    => array(
                                'contentful_id'         => $entry->sys->id,
                                'contentful_version'    => $entry->sys->version
                            )

                        );

                        //if already imported
                        if( count( $already ) > 0 )
                        {
                            //we need to overwrite the actual post, no duplicate
                            $args[ 'ID' ] = $already[ 0 ]->ID;
                        }

                        //adding post
                        $id = wp_insert_post( $args );

                        //storing fields temporarily
                        $fields_temp[ $id ] = array(
                            'id'        => $entry->sys->id,
                            'fields'    => $entry->fields,
                            'post_type' => $args[ 'post_type' ]
                        );

                    }
                }

                //adding fields to entries
                foreach ( $fields_temp as $id => $entry )
                {

                    //post information
                    $args = array(
                        'ID' => $id
                    );

                    //parsing fields
                    $this->parseFields( $entry[ 'fields' ], $args, $space );

                    //updating post
                    wp_update_post( $args );

                }

                //redirecting for no further importation
                echo '<script type="text/javascript">document.location.href = "' . admin_url(
                        'admin.php?page=contentful-wp'
                    ) . '";</script>';

            break;

        }

    }

    /**
     * @param array $fields
     * @param array $args
     * @throws \Exception
     */
    private function parseFields( $fields, &$args, $space = false )
    {

        //if no space set
        if( ! $space )
        {

            //retrieving space from database
            $space = get_option( 'contentful_space', false );

            //if still no space
            if( ! $space )
            {
                //nothing to do here
                return;
            }

        }

        //foreach fields
        $title_set = false;
        foreach ( $fields as $field => $value )
        {

            $value = (Array) $value;
            $locales = array_keys( $value );
            $value = $value[ $locales[ 0 ] ];

            //if no specific type
            if ( !isset( $value->sys ) || !isset( $value->sys->type ) )
            {

                //if it's an array
                if( is_array( $value ) )
                {
                    //getting each values
                    foreach( $value as $line )
                    {
                        //if no specific type
                        if ( !isset( $line->sys ) || !isset( $line->sys->type ) )
                        {
                            //value is the value
                            $v = $line;
                        }
                        else
                        {
                            //parsing sys value and getting its type
                            $v = $this->parseSysValue( $field, $line, $space );
                        }

                        //getting repeater index for this field
                        global ${ $field . '_repeater_' . $args[ 'ID' ] };

                        //if repeater index not set yet
                        if( ! ${ $field . '_repeater_' . $args[ 'ID' ] } )
                        {
                            //setting it to zero
                            ${ $field . '_repeater_' . $args[ 'ID' ] } = 0;
                        }

                        //setting value to repeater at specified index
                        $args[ 'meta_input' ][ $field . '_repeater_' . ${ $field . '_repeater_' . $args[ 'ID' ] } . '_' . $field ] = $v;


                        //if there is a sys
                        if( isset( $line->sys ) )
                        {
                            //if there is an id
                            if ( isset( $line->sys->id ) )
                            {
                                //adding contentful asset id for update purposes
                                $args[ 'meta_input' ][ '_contentful_' . $field . '_repeater_' . ${$field . '_repeater_' . $args[ 'ID' ]} . '_' . $field ] = $line->sys->id;
                            }

                            //if there is a type
                            if ( isset( $value->sys->linkType ) )
                            {
                                //adding a flag to keep track of the type
                                $args[ 'meta_input' ][ '_contentful_type_' . $field . '_repeater_' . ${$field . '_repeater_' . $args[ 'ID' ]} . '_' . $field ] = $value->sys->linkType;
                            }
                        }

                        //incrmenting index
                        $args[ 'meta_input' ][ $field . '_repeater' ] = ++${ $field . '_repeater_' . $args[ 'ID' ] };

                    }
                }
                else
                {

                    //if value is an object
                    if( is_object( $value ) )
                    {
                        //encoding into json
                        $value = json_encode( $value );
                    }

                    //adding field to post
                    $args[ 'meta_input' ][ $field ] = $value;

                    //if no title set yet
                    if ( !$title_set )
                    {
                        //setting title
                        $args[ 'post_title' ] = $value;

                        //title set
                        $title_set = true;
                    }

                }

            }
            else
            {
                //if the value is in sys

                //parsing sys value and getting its type
                $v = $this->parseSysValue( $field, $value, $space );

                //adding the value to the field
                $args[ 'meta_input' ][ $field ] = $v;

                //if there is an id
                if ( isset( $value->sys->id ) )
                {
                    //adding contentful asset id for update purposes
                    $args[ 'meta_input' ][ '_contentful_' . $field ] = $value->sys->id;
                }

                //if there is a type
                if ( isset( $value->sys->linkType ) )
                {
                    //adding a flag to keep track of the type
                    $args[ 'meta_input' ][ '_contentful_type_' . $field ] = $value->sys->linkType;
                }

            }

        }

    }

    /**
     * @param $field
     * @param $value
     * @param $args
     * @param string $key
     * @param bool|false $space
     * @return object
     * @throws \Exception
     */
    private function parseSysValue( $field, $value, $space = false )
    {

        //if no space set
        if( ! $space )
        {
            //getting space
            $space = get_option( 'contentful_space', false );

            //if no space defined
            if ( !$space )
            {
                //throwing an error
                throw new \Exception(
                    __( 'No space has been selected. Please select a space first.', Plugin::TEXTDOMAIN )
                );
            }
        }

        //wpdb instance
        global $wpdb;

        //value is false at default
        $v = false;

        //if it's an asset
        if ( $value->sys->linkType == 'Asset' )
        {

            //getting the asset
            $asset = $this->api->request( "spaces/$space/assets/{$value->sys->id}", 'get' );

            //foreach asset fields and values
            foreach ( $asset->fields as $asset_field => $asset_value )
            {

                //checking for file
                if ( $asset_field != 'file' )
                {
                    continue; //continue if not file
                }

                //getting first locale
                $asset_value = (Array) $asset_value;
                $locales = array_keys( $asset_value );
                $asset_value = $asset_value[ $locales[ 0 ] ];

                //uploading
                $upload_dir = wp_upload_dir( time() );

                //getting file name
                $last_slash_pos = strrpos( $asset_value->url, '/' );
                $filename_length = strlen( $asset_value->url ) - $last_slash_pos;
                $filename = substr( $asset_value->url, $last_slash_pos, $filename_length );

                //getting file type
                $filetype = wp_check_filetype( $filename );

                //getting image data
                $image = file_get_contents( str_replace( '//', 'https://', $asset_value->url ) );

                //uploading
                $upload_src = $upload_dir[ 'path' ] . $filename;
                file_put_contents( $upload_src, $image );

                //inserting attachment
                $attachment = array(
                    'guid'           => $upload_dir[ 'url' ] . '/' . basename( $filename ),
                    'post_mime_type' => $filetype[ 'type' ],
                    'post_title'     => basename( $filename ),
                    'post_content'   => '',
                    'post_status'    => 'inherit'
                );

                // Insert the attachment.
                $asset_id = wp_insert_attachment( $attachment, $upload_src );

                //adding Contentful id to attachment
                update_post_meta( $asset_id, 'contentful_id', $value->sys->id );

                //value retrived, setting it to value
                $v = $asset_id;

            }

        }
        else
        {
            if ( $value->sys->linkType == 'Entry' )
            {

                //getting the post id by its entry id
                $posts = $wpdb->get_results(
                    "

									SELECT ID FROM $wpdb->posts, $wpdb->postmeta
									WHERE $wpdb->posts.ID = $wpdb->postmeta.post_id
									AND $wpdb->postmeta.meta_key = 'contentful_id'
									AND $wpdb->postmeta.meta_value = '" . $value->sys->id . "'
									ORDER BY ID DESC

								"
                );

                //checking if post found
                if ( empty( $posts ) )
                {
                    return false; //nothing to do here
                }

                /*
                //otherwise, we set the field to this post ID
                if( ! isset( $args[ $key ][ $field ] ) )
                {
                    $args[ $key ][ $field ] = array();
                }
                $args[ $key ][ $field ][] = $posts[ 0 ]->ID;
                */
                $v = $posts[ 0 ]->ID;

            }
        }

        //returning value
        return $v;

    }

    private function generateContentTypes( $content_types )
    {

        //getting current content types
        $imported_content_types = get_option( 'contentful_content_types', array() );

        //if it's not an array
        if( ! is_array( $imported_content_types )  )
        {
            //decoding json
            $imported_content_types = json_decode( $imported_content_types, true );
        }

        //opening includes files
        $f = fopen( $this->path . '/content_types/content_types.php', 'w' );

        //writing begining of file
        fwrite( $f, "<?php\n" );
        fwrite( $f, "\n\n//DO NOT ALTER, THIS FILE IS GENERATED BY CONTENTFUL WP\n\n" );

        //for each content types
        foreach( $content_types->items as $content_type )
        {

            //generating post type id
            $post_type_id = uniqid( 'cwp_' );

            //checking if selected
            if( ! in_array( $content_type->sys->id, $_POST[ 'content_types' ] ) )
            {
                //continue to the next one
                continue;
            }
            else
            {

                //adding content type generated id
                $imported_content_types[ $content_type->sys->id ] = $post_type_id;
            }

            //checking that the content type is not already created
            if( file_exists( $this->path . "/content_types/" . $content_type->sys->id . ".php" ) )
            {
                //already created, including it
                fwrite( $f, "\ninclude_once '" . $content_type->sys->id . ".php';" );

                //pass to the next one
                continue;
            }

            //composing the php file
            ob_start();

            //beginning of file
            echo "<?php\n";

	        ?>

	        //Generated by Contentful WP
	        //Author: Tanios
	        //Developer: Guillaume Laliberté

	        add_action( 'init', 'contentful_register_cpt_<?php echo $post_type_id; ?>' );
	        function contentful_register_cpt_<?php echo $post_type_id; ?>() {
		        $labels = array(
			        "name" => "<?php echo $content_type->name; ?>",
			        "singular_name" => "<?php echo $content_type->name; ?>",
		        );

		        $args = array(
			        "labels" => $labels,
			        "description" => "<?php echo $content_type->description; ?>",
			        "public" => true,
			        "show_ui" => true,
			        "show_in_rest" => false,
			        "has_archive" => true,
			        "show_in_menu" => true,
			        "exclude_from_search" => false,
			        "capability_type" => "post",
			        "map_meta_cap" => true,
			        "hierarchical" => false,
			        "rewrite" => array( "slug" => "<?php echo sanitize_title( $content_type->name ); ?>", "with_front" => true ),
			        "query_var" => true,
			        "supports" => false,
		        );
		        register_post_type( "<?php echo $post_type_id; ?>", $args );
	        }

            if(function_exists("register_field_group"))
            {
                register_field_group(array (
                    'id' => 'acf_contentful_<?php echo $content_type->sys->id; ?>',
                    'title' => '<?php echo $content_type->name; ?>',
                    'fields' => array (

                        <?php
                        $field_types = array();
                        ?>

                        <?php foreach( $content_type->fields as $field ): ?>

					        <?php
                            //checking if disabled
                            if( ! empty( $field->disabled ) )
                            {
                                continue; //next field
                            }

	                        //not required by default
	                        $field->required = isset( $field->required ) ? $field->required : false;

                            //if it's an array
                            $is_array = false;
                            if( isset( $field->type ) && $field->type == 'Array' )
                            {

                                //we need to ensure that this is not an array of Entries
                                if(
                                    (
                                        isset( $field->items ) && isset( $field->items->type ) && $field->items->type == 'Link' &&
                                        isset( $field->items->linkType ) && $field->items->linkType == 'Entry'
                                    )
                                )
                                {

                                    $field->type = 'Link';
                                    $field->linkType = 'Entry';
                                    $field->is_array = true;

                                }
                                else
                                {

                                    //it's an array
                                    $is_array = true;

                                    //displaying a repeater
                                    ?>
                                    array (
                                    'key' => 'field_<?php echo uniqid(); ?>',
                                    'label' => '<?php echo $field->name; ?>',
                                    'name' => '<?php echo $field->id; ?>_repeater',
                                    'type' => 'repeater',
                                    'sub_fields' => array (
                                    <?php

                                    //getting fields type
                                    if ( isset( $field->items ) && isset( $field->items->type ) )
                                    {
                                        //we set the field type to the items type
                                        $field->type = $field->items->type;

                                        //if it's a link
                                        if ( $field->type == 'Link' )
                                        {
                                            //getting Link type
                                            if ( isset( $field->items->linkType ) )
                                            {
                                                //setting link type to field
                                                $field->linkType = $field->items->linkType;
                                            }
                                        }
                                    }
                                    else
                                    {
                                        //if it's not set, defaulting to symbol
                                        $field->type = 'Symbol';
                                    }

                                }

                            }
					        ?>

                            <?php switch( $field->type ):

		                    case "Symbol": ?>
                            array (
                                'key' => 'field_<?php echo uniqid(); ?>',
                                'label' => '<?php echo $field->name; ?>',
                                'name' => '<?php echo $field->id; ?>',
                                'type' => 'text',
                                'default_value' => '',
                                'placeholder' => '',
                                'prepend' => '',
                                'append' => '',
                                'formatting' => 'html',
                                'maxlength' => '256',
			                    'required' => '<?php echo $field->required; ?>'
                            ),
                            <?php break; ?>

                            <?php case "Text": ?>
                            array (
                                'key' => 'field_<?php echo uniqid(); ?>',
                                'label' => '<?php echo $field->name; ?>',
                                'name' => '<?php echo $field->id; ?>',
                                'type' => 'wysiwyg',
                                'default_value' => '',
                                'toolbar' => 'full',
                                'media_upload' => 'yes',
			                    'required' => '<?php echo $field->required; ?>'
                            ),
                            <?php break; ?>

                            <?php case "Integer": ?>
                            array (
                                'key' => 'field_<?php echo uniqid(); ?>',
                                'label' => '<?php echo $field->name; ?>',
                                'name' => '<?php echo $field->id; ?>',
                                'type' => 'number',
                                'default_value' => '',
                                'placeholder' => '',
                                'prepend' => '',
                                'append' => '',
                                'min' => '',
                                'max' => '',
                                'step' => '',
			                    'required' => '<?php echo $field->required; ?>'
                            ),
                            <?php break; ?>

                            <?php case "Number":?>
                            array (
                                'key' => 'field_<?php echo uniqid(); ?>',
                                'label' => '<?php echo $field->name; ?>',
                                'name' => '<?php echo $field->id; ?>',
                                'type' => 'number',
                                'default_value' => '',
                                'placeholder' => '',
                                'prepend' => '',
                                'append' => '',
                                'min' => '',
                                'max' => '',
                                'step' => '0.1',
			                    'required' => '<?php echo $field->required; ?>'
                            ),
                            <?php break; ?>

                            <?php case "Date": ?>
                            array (
                                'key' => 'field_<?php echo uniqid(); ?>',
                                'label' => '<?php echo $field->name; ?>',
                                'name' => '<?php echo $field->id; ?>',
                                'type' => 'date_picker',
                                'date_format' => 'yymmdd',
                                'display_format' => 'dd/mm/yy',
                                'first_day' => 1,
			                    'required' => '<?php echo $field->required; ?>'
                            ),
                            <?php break; ?>

                            <?php case "Boolean": ?>
                            array (
                                'key' => 'field_<?php echo uniqid(); ?>',
                                'label' => '<?php echo $field->name; ?>',
                                'name' => '<?php echo $field->id; ?>',
                                'type' => 'true_false',
                                'message' => '',
                                'default_value' => 0,
			                    'required' => '<?php echo $field->required; ?>'
                            ),
                            <?php break; ?>

                            <?php case "Link": ?>
                                <?php if( $field->linkType == 'Entry' ): ?>
						        array (
							        'key' => 'field_<?php echo uniqid(); ?>',
							        'label' => '<?php echo $field->name; ?>',
							        'name' => '<?php echo $field->id; ?>',
							        'type' => 'relationship',
							        'return_format' => 'id',
							        'post_type' => array (
							            0 => 'all',
							        ),
							        'taxonomy' => array (
							            0 => 'all',
							        ),
							        'filters' => array (
							            0 => 'search',
							        ),
							        'result_elements' => array (
							            0 => 'post_type',
							            1 => 'post_title',
							        ),
							        'max' => '',
				                    'required' => '<?php echo $field->required; ?>'
						        ),
				                <?php elseif( $field->linkType == 'Asset' ): ?>
                                array (
                                    'key' => 'field_<?php echo uniqid(); ?>',
                                    'label' => '<?php echo $field->name; ?>',
                                    'name' => '<?php echo $field->id; ?>',
                                    'type' => 'file',
                                    'column_width' => '',
                                    'save_format' => 'id',
                                    'library' => 'all',
                                    'required' => '<?php echo $field->required; ?>'
                                ),
                                <?php endif; ?>
                            <?php break; ?>
                            <?php case "Object": ?>

                            array (
                                'key' => 'field_<?php echo uniqid(); ?>',
                                'label' => '<?php echo $field->name; ?>',
                                'name' => '<?php echo $field->id; ?>',
                                'type' => 'textarea',
                                'default_value' => '',
                                'allow_null' => 0,
								'required' => '<?php echo $field->required; ?>'
                            ),

                            <?php break; ?>

                            <?php endswitch; ?>

                            <?php

                            //if it's an array
                            if( $is_array )
                            {
                                //closing field
                                ?>
                                    ),
                                    'row_min' => '',
                                    'row_limit' => '',
                                    'layout' => 'table',
                                    'button_label' => 'Add Row',
                                ),
                                <?php
                            }

                            //adding field type for later use
                            if( empty( $field->is_array  ) )
                            {
                                $field_types[ $field->id ] = $field->type == 'Link' ? $field->linkType : $field->type;
                            }
                            else
                            {
                                if ( $field->type == 'Link' )
                                {
                                    $field_types[ $field->id ] = $field->linkType == 'Asset' ? 'Assets' : 'Entries';
                                }
                                else
                                {
                                    $field_types[ $field->id ] = $field->type;
                                }
                            }

                            ?>

                        <?php endforeach; ?>
                    ),
                    'location' => array (
                        array (
                            array (
                                'param' => 'post_type',
                                'operator' => '==',
                                'value' => '<?php echo $post_type_id; ?>',
                                'order_no' => 0,
                                'group_no' => 0,
                            ),
                        ),
                    ),
                    'options' => array (
                        'position' => 'acf_after_title',
                        'layout' => 'default',
                        'hide_on_screen' => array (
                        ),
                    ),
                    'menu_order' => 0,
                ));
            }

            //filters for field type
            add_filter( 'contentful_field_type_<?php echo $post_type_id; ?>', function( $field ) {

                //defaulted to symbol
                $type = 'Symbol';

                //getting field type
                switch( $field )
                {
                    <?php foreach( $field_types as $field => $type ): ?>
                    case '<?php echo $field; ?>':
                        $type = '<?php echo $type; ?>';
                    break;
                    <?php endforeach; ?>
                }

                //returning type
                return $type;

            } );

            <?php
            //writing to file
            $content = ob_get_clean();
            file_put_contents( $this->path . "/content_types/" . $post_type_id . ".php", $content );

	        //adding include to includes file
	        fwrite( $f, "\ninclude_once '" . $post_type_id . ".php';" );


        }

        //closing includes file
        fclose( $f );

        //saving imported content types
        update_option( 'contentful_content_types', json_encode( $imported_content_types ) );

	    //redirecting
	    $redirect_to = admin_url( 'admin.php?page=contentful-wp&action=import_entries&nonce=' . wp_create_nonce( 'contentful_import_entries' ) . '&content_types=' . implode( ',', $_POST[ 'content_types' ] ) );
	    echo '<script type="text/javascript">document.location.href = "' . $redirect_to . '";</script>';

    }

    public function _actionConnect()
    {

        //checking if connect query isset
        if( get_query_var( 'contentful_wp_connect', false ) === false )
        {
            return; //nothing else to do here
        }

        //otherwise, we need to get the access token
        ?>

        <script type="text/javascript">

            //getting hash
            var hash = window.location.hash;

            //getting access token from it with regex
            var regex = /#access_token=([^&]+)/gi;
            var access_token = regex.exec( hash );

            //if no access token found
            if( access_token.length < 2 )
            {
                //access token is -1 for php to know
                access_token = -1;
            }
            else
            {
                //access token equals to the regex group found
                access_token = access_token[1];
            }

            //redirecting to admin
            document.location.href = '<?php echo admin_url( 'admin.php?page=contentful-wp&action=save_access_token&nonce=' . wp_create_nonce( 'contentful_save_access_token' ) . '&access_token=' ); ?>' + access_token;

        </script>

        <?php

    }

    public function _actionPostUpdated( $post_id, $post )
    {

        //if importing
        if( defined( 'DOING_CONTENTFUL_IMPORT' ) && DOING_CONTENTFUL_IMPORT )
        {
            //do not upload
            return $post_id;
        }

        ob_start();

        //getting content types
        $content_types = get_option( 'contentful_content_types', array() );

        //if it's not an array
        if( ! is_array( $content_types ) )
        {
            //decoding json
            $content_types = json_decode( $content_types, true );
        }

        //if post is not a contentful entry
        if( ! in_array( $post->post_type, $content_types ) )
        {
            //nothing to do here
            return $post_id;
        }

        //getting space
        $space = get_option( 'contentful_space', false );

        //if no space defined
        if( ! $space )
        {
            //nothing to do here
            return $post_id;
        }

        //getting contentful id
        $contentful_id = get_post_meta( $post_id, 'contentful_id', true );

        //getting contentful version
        $contentful_version = get_post_meta( $post_id, 'contentful_version', true );

        //---------------------------------------

        //no contentful id  = insert
        //contentful id     = update

        $is_update = ! empty( $contentful_id );

        //----------------------------------------


        //getting fields
        $fields = get_post_custom( $post_id );

        //cleaning fields
        $tmp = array();
        foreach( $fields as $key => $value )
        {
            //if not specified
            if( empty( $value[ 0 ] ) )
            {
                continue; //next field
            }

            //if it's a wordpress field
            if( substr( $key, 0, 1 ) == '_' )
            {
                //next field
                continue;
            }

            //if the field is the contentful id
            if( $key == 'contentful_id' )
            {
                //next field
                continue;
            }

            //if the field is the contentful version
            if( $key == 'contentful_version' )
            {
                 //next field
                continue;
            }

            //if repeater length
            if( preg_match( '/_repeater$/', $key ) )
            {
                //next field
                continue;
            }

            //name
            $repeater_index = strpos( $key, '_repeater_' );
            $name = substr( $key, 0, $repeater_index > -1 ? $repeater_index : strlen( $key ) );

            //getting type
            $type = apply_filters( 'contentful_field_type_' . $post->post_type, $name );

            //if asset
            if( $type == 'Asset' || $type == 'Assets' )
            {

                //getting asset contentful id
                $asset = get_post_meta( $value[ 0 ], 'contentful_id', true );

                //if the asset has already been uploaded to Contentful
                if( $asset )
                {
                    //changing value to its id
                    $value[ 0 ] = new \stdClass();
                    $sys = new \stdClass();
                    $sys->type = 'Link';
                    $sys->linkType = 'Asset';
                    $sys->id = $asset;
                    $value[ 0 ]->sys = $sys;
                }
                else
                {
                    //if not, we need to upload it
                    try
                    {
                        //getting attachment information
                        $asset_meta = wp_prepare_attachment_for_js( $value[ 0 ] );

                        $asset = $this->api->request(
                            "spaces/$space/assets",
                            'POST',
                            array(
                                'fields' => array(
                                    'title' => array(
                                        'en-US' => $asset_meta[ 'title' ]
                                    ),
                                    'file'  => array(
                                        'en-US' => array(
                                            'contentType' => $asset_meta[ 'mime' ],
                                            'fileName'    => $asset_meta[ 'filename' ],
                                            'upload'      => $asset_meta[ 'url' ]
                                        )
                                    )
                                )
                            )
                        );

                        //if there is an asset returned with an id
                        if( isset( $asset->sys ) && isset( $asset->sys->id ) )
                        {
                            //updating attachment contentful id
                            update_post_meta( $value[ 0 ], 'contentful_id', $asset->sys->id );

                            //adding type and id to post
                            update_post_meta( $post_id, "_contentful_$key", $asset->sys->id );
                            update_post_meta( $post_id, "_contentful_type_$key", 'Asset' );

                            //setting value to this id
                            $value[ 0 ] = new \stdClass();
                            $sys = new \stdClass();
                            $sys->type = 'Link';
                            $sys->linkType = 'Asset';
                            $sys->id = $asset->sys->id;
                            $value[ 0 ]->sys = $sys;

                            //processing asset
                            $this->api->request( "spaces/$space/assets/{$asset->sys->id}/files/en-US/process", 'PUT', array(), array(
                                'X-Contentful-Version: ' . $asset->sys->version
                            ) );

                            //publishing asset
                            $this->api->request( "spaces/$space/assets/{$asset->sys->id}/published", 'PUT', array(), array(
                                'X-Contentful-Version: ' . $asset->sys->version
                            ) );

                        }

                    }
                    catch( \Exception $e ) {
                        continue;
                    }

                }

            }
            else if( $type == 'Entry' || $type == 'Entries' )
            {

                //getting post ids
                $ids = maybe_unserialize( $value[ 0 ] );

                //if more than one posts, array
                if( $type == 'Entries' )
                {
                    $value[ 0 ] = array();

                    //foreach posts
                    foreach( $ids as $id )
                    {
                        //getting post contentful id
                        $linked_post = get_post_meta( $id, 'contentful_id', true );

                        //if no post
                        if( ! $linked_post )
                        {
                            continue; //next post
                        }

                        //otherwise, adding field
                        $v = new \stdClass();
                        $sys = new \stdClass();
                        $sys->type = 'Link';
                        $sys->linkType = 'Entry';
                        $sys->id = $linked_post;
                        $v->sys = $sys;
                        $value[ 0 ][] = $v;
                    }


                }
                else
                {

                    //if only one post, getting its contentful id
                    $linked_post = get_post_meta( $ids[ 0 ], 'contentful_id', true );

                    //adding to field
                    $value[ 0 ] = new \stdClass();
                    $sys = new \stdClass();
                    $sys->type = 'Link';
                    $sys->linkType = 'Entry';
                    $sys->id = $linked_post;
                    $value[ 0 ]->sys = $sys;

                }

                echo $key . "\n";
                print_r( $value[ 0 ] );
                echo "\n\n";

            }
            else
            {

                //parsing value
                $value[ 0 ] = $this->parseValue( $value[ 0 ], $type );

            }

            //if this is a repeater
            if( strpos( $key, '_repeater_' ) > -1 )
            {

                if( empty( $value[ 0 ] ) )
                {
                    continue;
                }

                //if the field is not set yet
                if( ! isset( $tmp[ $name ] ) )
                {
                    //new array
                    $tmp[ $name ] = array(
                        'en-US' => array()
                    );
                }

                //adding value to array
                $tmp[ $name ][ 'en-US' ][] = $value[ 0 ];

                //next field
                continue;

            }

            //otherwise, adding first value to field
            $tmp[ $key ] = array(
                'en-US' => $value[ 0 ]
            );

        }

        //fields are cleaned
        $fields = $tmp;

        try
        {
            //making request
            if( ! $is_update ) //create
            {

                //create the entry and get it back from server
                $entry = $this->api->request( "spaces/$space/entries", 'POST', array(
                    'fields'        => $fields
                ), array(
                    'X-Contentful-Content-Type: ' . array_search( $post->post_type, $content_types )
                ) );

                print_r( $entry );

                //if there is entry sys
                if( isset( $entry->sys ) )
                {

                    //if there is an error
                    if( isset( $entry->sys->type ) && $entry->sys->type != 'Error' )
                    {

                        //if there is an entry id
                        if ( isset( $entry->sys->id ) )
                        {
                            //setting id
                            update_post_meta( $post_id, 'contentful_id', $entry->sys->id );
                        }

                        //if there is an entry version
                        if ( isset( $entry->sys->version ) )
                        {
                            //setting version
                            update_post_meta( $post_id, 'contentful_version', $entry->sys->version );
                        }

                    }

                }

            }
            else //update
            {

                //create the entry and get it back from server
                $entry = $this->api->request( "spaces/$space/entries/$contentful_id", 'PUT', array(
                    'fields'        => $fields
                ), array(
                    'X-Contentful-Content-Type: ' . array_search( $post->post_type, $content_types ),
                    'X-Contentful-Version: ' . $contentful_version
                ) );

                print_r( $entry );

                //if there is entry sys
                if( isset( $entry->sys ) )
                {

                    //if there is an error
                    if( isset( $entry->sys->type ) && $entry->sys->type != 'Error' )
                    {

                        //if there is an entry id
                        if ( isset( $entry->sys->id ) && $entry->sys->id != 'NotFound' )
                        {
                            //setting id
                            update_post_meta( $post_id, 'contentful_id', $entry->sys->id );
                        }

                        //if there is an entry version
                        if ( isset( $entry->sys->version ) )
                        {
                            //setting version
                            update_post_meta( $post_id, 'contentful_version', $entry->sys->version );
                        }

                    }

                }

            }

            //if can publish
            if( isset( $entry->sys->id ) && isset( $entry->sys->version ) )
            {

                //if there is an error
                if( isset( $entry->sys->type ) && $entry->sys->type != 'Error' )
                {

                    //publishing
                    $entry = $this->api->request(
                        "spaces/$space/entries/{$entry->sys->id}/published",
                        'PUT',
                        array(),
                        array(
                            "X-Contentful-Version: {$entry->sys->version}"
                        )
                    );

                    print_r( $entry );

                    //if there is a new entry version
                    if ( isset( $entry->sys ) && isset( $entry->sys->version ) )
                    {

                        //syncing Contentful version
                        update_post_meta( $post_id, 'contentful_version', $entry->sys->version );

                    }

                }

            }

        }
        catch( \Exception $e )
        {
            echo $e->getMessage();
        }


        //print_r( $fields );

        $log = ob_get_clean();

        $f = fopen( $this->path . '/content_types/log.txt', 'w' );
        fwrite( $f, $log );
        fclose( $f );

        return $post_id;

    }

    public function _actionSync()
    {

        if ( strpos( $_SERVER['SCRIPT_NAME'], '/wp-admin/post.php' ) > -1 && $_SERVER[ 'REQUEST_METHOD' ] == 'GET' )
        {

            //getting post id
            $id = $_GET['post'] ? $_GET['post'] : $_POST['post_ID'];

            //getting post
            $post = get_post( $id );

            //getting content types
            $content_types = get_option( 'contentful_content_types', array() );

            //if it's not an array
            if( ! is_array( $content_types ) )
            {
                //decoding json
                $content_types = json_decode( $content_types, true );
            }

            //if post is not a contentful entry
            if( ! in_array( $post->post_type, $content_types ) )
            {
                //nothing to do here
                return;
            }

            //getting space
            $space = get_option( 'contentful_space', false );

            //if no space
            if( ! $space )
            {
                return; //nothing to do here
            }

            //getting contentful id
            $contentful_id = get_post_meta( $id, 'contentful_id', true );

            //if there is not contentful id
            if( ! $contentful_id )
            {
                return; //nothing to do here
            }

            //getting last contentful version
            try
            {

                //making the request
                $response = $this->api->request( "spaces/$space/entries/$contentful_id", 'GET' );

                //checking for error
                if( isset( $response->sys ) && isset( $response->sys->type ) && $response->sys->type == 'Error' )
                {
                    return; //there was an error, do not update post otherwise, VersionMismatch
                }

                //preparing to update post
                $args = array(
                    'ID'    => $id
                );

                //parsing fields
                $this->parseFields( $response->fields, $args, $space );

                //getting contentful id and version
                $contentful_id = $response->sys->id;
                $contentful_version = $response->sys->version;

                //passing new id and new version to meta_input
                $args[ 'meta_input' ][ 'contentful_id' ] = $contentful_id;
                $args[ 'meta_input' ][ 'contentful_version' ] = $contentful_version;

                //doing import
                define( 'DOING_CONTENTFUL_IMPORT', true );

                //updating post
                wp_update_post( $args );

            }
            catch( \Exception $e )
            {
                return; //nothing to do
            }

        }

    }

    private function parseValue( $value, $type )
    {

        //if value is not an array
        if( ! is_array( $value ) )
        {

            switch( $type )
            {
                case 'Number':
                    $value = floatval( $value );
                break;
                case 'Integer':
                    $value = intval( $value );
                break;
                default:

                    //checking if value is a json object
                    $json = json_decode( $value );
                    if( json_last_error() == JSON_ERROR_NONE )
                    {

                        //checking if it's a location
                        if( isset( $json->lat ) && isset( $json->lon ) )
                        {
                            $value = $json;
                        }

                    }

                break;
            }

        }
        else
        {

            foreach ( $value as $k => $v )
            {
                switch( $type )
                {
                    case 'Number':
                        $value[ $k ] = floatval( $value );
                    break;
                    case 'Integer':
                        $value[ $k ] = intval( $value );
                    break;
                }
            }

        }

        return $value;

    }

    /**
     * @return string Returns the path of the plugin's directory
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @return string Returns the URI of the plugin's directory
     */
    public function getUri()
    {

        return $this->uri;
    }

    /**
     * @return string Returns the version of the plugin
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @return Plugin
     */
    public static function getInstance()
    {
        //returning the instance
        return self::$instance;
    }

}