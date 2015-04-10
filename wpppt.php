<?php
/*
Plugin Name: WP-Parsi Permalink Translator
Plugin URI: http://wp-parsi.com
Description: Automatic translate post title for use as slug
Author: Parsa Kafi
Version: 1.0
Author URI: http://parsa.ws
Text Domain: wpp-permalink-translator
Domain Path: languages
*/

class WPPPT_Plugin
{
    protected $_translate_one_key = "_wpppt_translate_one";
    protected $_translate_key = "_wpppt_translate";
    protected $options_key = "wpp-permalink-translator";
    protected $text_domain = "wpp-permalink-translator";
    protected $api_url = "https://api.datamarket.azure.com/Bing/MicrosoftTranslator/v1/Translate";

    function __construct()
    {
        add_filter( 'wp_insert_post_data', array( $this, 'translate_title' ), '99', 2 );
        add_action( 'admin_head-post.php', array( $this, 'translate_checkbox' ) );
        add_action( 'admin_head-post-new.php', array( $this, 'translate_checkbox' ) );
        add_filter( 'wp_unique_post_slug', array( $this, 'post_slug' ), 10, 4 );
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'activated_plugin', array( $this, 'activation_redirect' ) );
        add_action( 'admin_menu', array( $this, "settings_menu" ) );
    }

    function activation_redirect( $plugin )
    {
        if ( $plugin == plugin_basename( __FILE__ ) ) {
            exit( wp_redirect( admin_url( 'options-general.php?page=' . $this->options_key ) ) );
        }
    }

    function init()
    {
        load_plugin_textdomain( $this->text_domain, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    function activate()
    {
        add_option( $this->options_key, array( "from" => "fa", "to" => "en", "ptype" => array( "post" => "post" ) ) );
    }

    function post_slug( $slug, $post_ID, $post_status, $post_type )
    {
        $opt = get_option( $this->options_key );
        $_wpppt_translate_one = get_post_meta( $post_ID, $this->_translate_one_key, true );

        if ( in_array( $post_type, $opt['ptype'] ) && !empty( $opt['key'] ) && $_wpppt_translate_one != 1 ) {
            $slug = str_replace( "-", " ", urldecode( $slug ) );
            $slug = sanitize_title( $this->translate( $slug ) );
            update_post_meta( $post_ID, $this->_translate_one_key, 1 );
        }

        return $slug;
    }

    function translate_title( $data, $postarr )
    {
        if ( $postarr['screen'] == 'edit-post' )
            return $data;

        update_post_meta( $postarr['ID'], $this->_translate_key, $_POST[$this->_translate_key] );

        if ( !$_POST[$this->_translate_key] )
            return $data;

        $opt = get_option( $this->options_key );

        if ( in_array( $data['post_type'], $opt['ptype'] ) && !empty( $opt['key'] ) ) {
            $text = $data['post_title'];
            $translate = $this->translate( $text );
            $data['post_name'] = sanitize_title( $translate );
        }

        return $data;
    }

    function translate_checkbox()
    {
        global $post_type, $post, $pagenow;
        $post_type = empty( $post_type ) ? "post" : $post_type;
        $post_id = $post->ID;
        $opt = get_option( $this->options_key );
        if ( !in_array( $post_type, $opt['ptype'] ) || empty( $opt['key'] ) )
            return;

        if ( $post_id )
            $_wpppt_translate = get_post_meta( $post_id, $this->_translate_key, true );

        if ( $_wpppt_translate == null && $pagenow == "post.php" )
            $_wpppt_translate = 0;
        else
            $_wpppt_translate = 1;
        ?>
        <script>
            var _wpppt_translate = <?php echo $_wpppt_translate ?>;

            jQuery(document).ready(function () {
                if (jQuery("#edit-slug-box #sample-permalink").length)
                    jQuery("#edit-slug-box #sample-permalink").after(' <label style="cursor: pointer;"><input type="checkbox" class="wpppt_translate" name="<?php echo $this->_translate_key ?>" value="1" <?php echo ($_wpppt_translate==1 ? "checked" : "") ?>/><?php _e( "Translate Title for Slug",  $this->text_domain ) ?></label> ');

                jQuery(document).ajaxComplete(function () {
                    if (jQuery("#edit-slug-box #sample-permalink").length && jQuery(".wpppt_translate").length == 0)
                        jQuery("#edit-slug-box #sample-permalink").after(' <label style="cursor: pointer;"><input type="checkbox" class="wpppt_translate" name="<?php echo $this->_translate_key ?>" value="1"/><?php _e( "Translate Title for Slug",  $this->text_domain ) ?></label> ');

                    if (_wpppt_translate == 1)
                        jQuery(".wpppt_translate").prop("checked", true);
                });

                jQuery(document).on('change', '.wpppt_translate', function () {
                    if (jQuery(this).prop('checked'))
                        _wpppt_translate = 1;
                    else
                        _wpppt_translate = 0;
                });
            });
        </script>

    <?php
    }

    function translate( $text )
    {
        $opt = get_option( $this->options_key );
        $url = $this->api_url;
        $key = $opt['key'];
        $from = $opt['from'];
        $to = $opt['to'];

        $request = $url;
        $request .= "?Text=%27" . urlencode( $text ) . "%27";
        $request .= "&To=%27" . $to . "%27";
        $request .= "&From=%27" . $from . "%27";
        $request .= "&\$top=1";
        $request .= "&\$format=json";
        $auth = base64_encode( "$key:$key" );
        $redata = array(
            'http' => array(
                'request_fulluri' => true,
                'ignore_errors' => true,
                'header' => "Authorization: Basic $auth" )
        );
        $context = stream_context_create( $redata );
        $response = file_get_contents( $request, 0, $context );
        $response = json_decode( $response, true );
        if ( isset( $response['d']['results'][0]['Text'] ) )
            $response = $response['d']['results'][0]['Text'];
        else
            $response = "";
        return $response;
    }


    function settings_menu()
    {
        add_options_page( __( "Permalink Translator", $this->text_domain ), __( "Permalink Translator", $this->text_domain ), "manage_options", $this->options_key, array( $this, "settings_page" ) );
    }

    function settings_page()
    {
        if ( isset( $_POST['wpppt_submit'] ) ) {
            update_option( $this->options_key, $_POST );
        }
        $ignore_post_types = array( "attachment", "revision", "nav_menu_item" );
        $post_types = get_post_types( "", "objects" );
        $opt = get_option( $this->options_key );
        $from = empty( $opt['from'] ) ? "fa" : $opt['from'];
        $to = empty( $opt['to'] ) ? "en" : $opt['to'];
        ?>
        <style>
            .wpppt_wrap table {
                width: 100%;
            }

            .wpppt_wrap input[type="text"] {
                width: 360px;
            }

            .wpppt_wrap .dashicons {
                font-size: 25px;
                margin-right: 10px;
            }

            .rtl .wpppt_wrap .dashicons {
                margin: 0 0 0 10px;
            }

            .wpppt-api-help {
                cursor: pointer;
            }

            .wpppt-api-note {
                display: none;
            }
        </style>
        <script>
            jQuery(document).ready(function () {
                jQuery(".wpppt-api-help").click(function () {
                    jQuery(".wpppt-api-note").slideToggle();
                });
            });
        </script>
        <div class="wrap wpppt_wrap">
            <div class="title">
                <h1><span
                        class="dashicons dashicons-admin-settings"></span><?php _e( "WP-Parsi Permalink Translator", $this->text_domain ) ?>
                </h1>
            </div>

            <?php if ( isset( $_POST['wpppt_submit'] ) ) echo '<div class="updated" id="message"><p>' . __( "Settings saved.", $this->text_domain ) . '</p></div>'; ?>

            <form action="" method="post">
                <table border="0">
                    <tr>
                        <td style="width: 200px"
                            valign="top"><?php _e( "Microsoft Account Key:", $this->text_domain ) ?></td>
                        <td><input type="text" name="key" value="<?php echo $opt['key'] ?>" class="ltr"/>
                            <span class="dashicons dashicons-editor-help wpppt-api-help"></span>
                            <br/>

                            <div class="wpppt-api-note">
                                <?php _e( "This plugin use Microsoft Translator API for translate posts slug. for active plugin go to Microsoft Azure Marketplace Account > <a href='https://datamarket.azure.com/account/keys' target='_blank'>Account Keys</a> and copy Default key or create new key for set this option. And then go to <a href='https://datamarket.azure.com/dataset/bing/microsofttranslator' target='_blank'>Microsoft Translator page</a> and active translate plan (Free plan maximum 2,000,000 Characters per month)", $this->text_domain ) ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e( "From Language:", $this->text_domain ) ?></td>
                        <td><?php $this->language_select( "from", "", $from ) ?></td>
                    </tr>
                    <tr>
                        <td><?php _e( "To Language:", $this->text_domain ) ?></td>
                        <td><?php $this->language_select( "to", "", $to ) ?></td>
                    </tr>
                    <tr>
                        <td valign="top"><?php _e( "Post Type:", $this->text_domain ) ?></td>
                        <td><?php
                            foreach ( $post_types as $post_type ) {
                                if ( !in_array( $post_type->name, $ignore_post_types ) )
                                    echo '<label><input type="checkbox" name="ptype[' . $post_type->name . ']" value="' . $post_type->name . '" ' . checked( $post_type->name, $opt['ptype'][$post_type->name], false ) . '/> ' . $post_type->label . '</label></br>';
                            }
                            ?></td>
                    </tr>

                    <tr>
                        <td></td>
                        <td><input type="submit" class="button button-primary " name="wpppt_submit"
                                   value="<?php _e( "Save" ) ?>"/></td>
                    </tr>
                </table>
            </form>
            <hr/>
            <a href="http://parsa.ws" target="_blank"><?php _e( "Parsa Web Design", $this->text_domain ) ?></a>

        </div>
    <?php
    }

    function language_select( $name, $empty = "", $selected = "", $echo = true )
    {
        $langs = array(
            'ar' => __( 'Arabic', $this->text_domain ),
            'bg' => __( 'Bulgarian', $this->text_domain ),
            'ca' => __( 'Catalan', $this->text_domain ),
            'cs' => __( 'Czech', $this->text_domain ),
            'da' => __( 'Danish', $this->text_domain ),
            'de' => __( 'German', $this->text_domain ),
            'el' => __( 'Greek', $this->text_domain ),
            'en' => __( 'English', $this->text_domain ),
            'es' => __( 'Spanish', $this->text_domain ),
            'et' => __( 'Estonian', $this->text_domain ),
            'fa' => __( 'Farsi', $this->text_domain ),
            'fi' => __( 'Finnish', $this->text_domain ),
            'fr' => __( 'French', $this->text_domain ),
            'hi' => __( 'Hindi', $this->text_domain ),
            'ht' => __( 'Haitian Creole', $this->text_domain ),
            'hu' => __( 'Hungarian', $this->text_domain ),
            'it' => __( 'Italian', $this->text_domain ),
            'ja' => __( 'Japanese', $this->text_domain ),
            'ko' => __( 'Korean', $this->text_domain ),
            'lt' => __( 'Lithuanian', $this->text_domain ),
            'lv' => __( 'Latvian (Lettish)', $this->text_domain ),
            'ms' => __( 'Malay', $this->text_domain ),
            'nl' => __( 'Dutch', $this->text_domain ),
            'no' => __( 'Norwegian', $this->text_domain ),
            'pl' => __( 'Polish', $this->text_domain ),
            'pt' => __( 'Portuguese', $this->text_domain ),
            'ro' => __( 'Romanian', $this->text_domain ),
            'ru' => __( 'Russian', $this->text_domain ),
            'sk' => __( 'Slovak', $this->text_domain ),
            'sl' => __( 'Slovenian', $this->text_domain ),
            'sv' => __( 'Swedish', $this->text_domain ),
            'th' => __( 'Thai', $this->text_domain ),
            'tr' => __( 'Turkish', $this->text_domain ),
            'uk' => __( 'Ukrainian', $this->text_domain ),
            'ur' => __( 'Urdu', $this->text_domain ),
            'vi' => __( 'Vietnamese', $this->text_domain )
        );

        $select = "<select name='$name' id='$name'>";

        if ( $empty != "" )
            $select .= "<option value=''>$empty</option>";

        foreach ( $langs as $key => $val ) {
            $select .= "<option value='$key' " . selected( $key, $selected, false ) . ">$val</option>";
        }

        $select .= "</select>";
        if ( $echo )
            echo $select;
        else
            return $select;
    }
}

$wpppt_plugin = new WPPPT_Plugin();