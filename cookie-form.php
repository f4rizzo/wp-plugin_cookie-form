<?php
/**
 * Plugin Name: Cookie Form Download Gate
 * Description: Blocca il primo download PDF con form (nome, email, azienda) e sblocca i successivi tramite cookie.
 * Version: 1.2.1
 * Author: DevMy
 * License: GPL2+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cookie_Form_PDF_Gate {
    const NONCE_ACTION = 'cookie_form_pdf_gate';
    const COOKIE_NAME  = 'cookie_form_pdf_gate_unlocked';

    public function __construct() {
        add_action( 'init', array( $this, 'register_lead_post_type' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

        add_shortcode( 'cookie_form_pdf_button', array( $this, 'render_pdf_button_shortcode' ) );
        add_shortcode( 'devmy_pdf_button', array( $this, 'render_pdf_button_shortcode' ) );
        add_shortcode( 'cookie_form_pdf_gate_form', array( $this, 'render_pdf_form_shortcode' ) );
        add_shortcode( 'devmy_pdf_gate_form', array( $this, 'render_pdf_form_shortcode' ) );

        add_action( 'wp_ajax_cookie_form_submit_pdf_gate', array( $this, 'handle_form_submission' ) );
        add_action( 'wp_ajax_nopriv_cookie_form_submit_pdf_gate', array( $this, 'handle_form_submission' ) );
        add_action( 'wp_ajax_devmy_submit_pdf_gate', array( $this, 'handle_form_submission' ) );
        add_action( 'wp_ajax_nopriv_devmy_submit_pdf_gate', array( $this, 'handle_form_submission' ) );

        if ( is_admin() ) {
            add_filter( 'manage_edit-cookie_form_lead_columns', array( $this, 'set_lead_columns' ) );
            add_action( 'manage_cookie_form_lead_posts_custom_column', array( $this, 'render_lead_column' ), 10, 2 );
            add_action( 'restrict_manage_posts', array( $this, 'render_export_button' ) );
            add_action( 'admin_init', array( $this, 'maybe_export_csv' ) );
        }
    }

    public static function activate() {
        $instance = new self();
        $instance->register_lead_post_type();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public function register_lead_post_type() {
        register_post_type(
            'cookie_form_lead',
            array(
                'labels' => array(
                    'name'          => __( 'eBook Leads', 'cookie-form' ),
                    'singular_name' => __( 'eBook Lead', 'cookie-form' ),
                ),
                'public'              => false,
                'show_ui'             => true,
                'show_in_menu'        => true,
                'menu_position'       => 25,
                'menu_icon'           => 'dashicons-download',
                'supports'            => array( 'title' ),
                'capability_type'     => 'post',
                'map_meta_cap'        => true,
                'exclude_from_search' => true,
                'publicly_queryable'  => false,
            )
        );
    }

    public function register_assets() {
        $base_url = plugin_dir_url( __FILE__ );

        wp_register_style(
            'cookie-form-pdf-gate',
            $base_url . 'assets/css/cookie-form.css',
            array(),
            '1.2.1'
        );

        wp_register_script(
            'cookie-form-pdf-gate',
            $base_url . 'assets/js/cookie-form.js',
            array( 'jquery' ),
            '1.2.1',
            true
        );
    }

    public function set_lead_columns( $columns ) {
        return array(
            'cb'            => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
            'name'          => __( 'Nome', 'cookie-form' ),
            'email'         => __( 'Email', 'cookie-form' ),
            'company'       => __( 'Azienda', 'cookie-form' ),
            'requested_pdf' => __( 'PDF richiesto', 'cookie-form' ),
            'date'          => isset( $columns['date'] ) ? $columns['date'] : __( 'Date', 'cookie-form' ),
        );
    }

    public function render_lead_column( $column, $post_id ) {
        switch ( $column ) {
            case 'name':
                $name = get_post_meta( $post_id, 'name', true );
                echo esc_html( $name ? $name : '-' );
                break;

            case 'email':
                $email = get_post_meta( $post_id, 'email', true );
                if ( $email && is_email( $email ) ) {
                    printf(
                        '<a href="%1$s">%2$s</a>',
                        esc_url( 'mailto:' . sanitize_email( $email ) ),
                        esc_html( $email )
                    );
                } else {
                    echo esc_html( $email ? $email : '-' );
                }
                break;

            case 'company':
                $company = get_post_meta( $post_id, 'company', true );
                echo esc_html( $company ? $company : '-' );
                break;

            case 'requested_pdf':
                $requested_pdf = get_post_meta( $post_id, 'requested_pdf', true );
                if ( ! $requested_pdf ) {
                    echo '-';
                    break;
                }

                $path    = wp_parse_url( $requested_pdf, PHP_URL_PATH );
                $pdfName = $path ? wp_basename( $path ) : $requested_pdf;

                printf(
                    '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                    esc_url( $requested_pdf ),
                    esc_html( $pdfName )
                );
                break;
        }
    }

    public function render_export_button( $post_type ) {
        if ( 'cookie_form_lead' !== $post_type ) {
            return;
        }

        $export_url = wp_nonce_url(
            add_query_arg(
                array(
                    'post_type'          => 'cookie_form_lead',
                    'cookie_form_export' => 'csv',
                ),
                admin_url( 'edit.php' )
            ),
            'cookie_form_export_csv',
            'cookie_form_export_nonce'
        );

        printf(
            '<a href="%1$s" class="button button-secondary" style="margin-left:8px;">%2$s</a>',
            esc_url( $export_url ),
            esc_html__( 'Esporta CSV', 'cookie-form' )
        );
    }

    public function maybe_export_csv() {
        if ( ! is_admin() ) {
            return;
        }

        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        $format    = isset( $_GET['cookie_form_export'] ) ? sanitize_key( wp_unslash( $_GET['cookie_form_export'] ) ) : '';

        if ( 'cookie_form_lead' !== $post_type || 'csv' !== $format ) {
            return;
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Non hai i permessi per esportare i lead.', 'cookie-form' ) );
        }

        check_admin_referer( 'cookie_form_export_csv', 'cookie_form_export_nonce' );

        $leads = get_posts(
            array(
                'post_type'              => 'cookie_form_lead',
                'post_status'            => array( 'private' ),
                'posts_per_page'         => -1,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );

        $filename = sprintf( 'cookie-form-leads-%s.csv', wp_date( 'Y-m-d-His' ) );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        if ( false === $output ) {
            wp_die( esc_html__( 'Impossibile generare il file CSV.', 'cookie-form' ) );
        }

        // UTF-8 BOM for Excel compatibility.
        fwrite( $output, "\xEF\xBB\xBF" );

        fputcsv(
            $output,
            array(
                'Nome',
                'Email',
                'Azienda',
                'Pagina origine',
                'PDF richiesto',
                'IP address',
                'User agent',
                'Data invio',
            )
        );

        foreach ( $leads as $lead_id ) {
            fputcsv(
                $output,
                array(
                    (string) get_post_meta( $lead_id, 'name', true ),
                    (string) get_post_meta( $lead_id, 'email', true ),
                    (string) get_post_meta( $lead_id, 'company', true ),
                    (string) get_post_meta( $lead_id, 'source_url', true ),
                    (string) get_post_meta( $lead_id, 'requested_pdf', true ),
                    (string) get_post_meta( $lead_id, 'ip_address', true ),
                    (string) get_post_meta( $lead_id, 'user_agent', true ),
                    (string) get_the_date( 'Y-m-d H:i:s', $lead_id ),
                )
            );
        }

        fclose( $output );
        exit;
    }

    private function enqueue_assets() {
        wp_enqueue_style( 'cookie-form-pdf-gate' );
        wp_enqueue_script( 'cookie-form-pdf-gate' );

        wp_localize_script(
            'cookie-form-pdf-gate',
            'cookieFormPdfGate',
            array(
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
                'cookieName'  => self::COOKIE_NAME,
                'cookieDays'  => 365,
                'errorText'   => __( 'Si e verificato un errore. Riprova.', 'cookie-form' ),
                'successText' => __( 'Grazie! Download sbloccato.', 'cookie-form' ),
            )
        );
    }

    public function render_pdf_button_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'pdf_url' => '',
                'label'   => __( 'Scarica PDF', 'cookie-form' ),
                'class'   => '',
                'target'  => '_blank',
            ),
            $atts,
            'cookie_form_pdf_button'
        );

        $pdf_url = esc_url_raw( $atts['pdf_url'] );
        if ( empty( $pdf_url ) ) {
            return '';
        }

        $this->enqueue_assets();

        $class_names = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $atts['class'] ) ) );
        $class_attr  = implode( ' ', $class_names );

        $output  = '<a class="devmy-pdf-download ' . esc_attr( $class_attr ) . '" href="' . esc_url( $pdf_url ) . '" data-pdf-url="' . esc_url( $pdf_url ) . '" data-target="' . esc_attr( $atts['target'] ) . '">';
        $output .= esc_html( $atts['label'] );
        $output .= '</a>';

        static $modal_rendered = false;
        if ( ! $modal_rendered ) {
            $modal_rendered = true;
            $output        .= $this->render_modal_markup();
        }

        return $output;
    }

    public function render_pdf_form_shortcode() {
        $this->enqueue_assets();
        return $this->render_form_markup( false );
    }

    private function render_modal_markup() {
        ob_start();
        ?>
        <div class="devmy-pdf-modal" id="devmy-pdf-gate-modal" aria-hidden="true">
            <div class="devmy-pdf-modal__overlay" data-close="1"></div>
            <div class="devmy-pdf-modal__panel" role="dialog" aria-modal="true" aria-labelledby="devmy-pdf-modal-title">
                <button type="button" class="devmy-pdf-modal__close" data-close="1" aria-label="Chiudi">&times;</button>
                <h3 id="devmy-pdf-modal-title"><?php esc_html_e( 'Compila il form per scaricare il PDF', 'cookie-form' ); ?></h3>
                <?php echo $this->render_form_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function render_form_markup( $with_id = true ) {
        ob_start();
        ?>
        <form <?php echo $with_id ? 'id="devmy-pdf-gate-form"' : ''; ?> class="devmy-pdf-form">
            <input type="hidden" name="requested_pdf" value="" />
            <label>
                <?php esc_html_e( 'Nome', 'cookie-form' ); ?>
                <input type="text" name="name" required />
            </label>
            <label>
                <?php esc_html_e( 'Email', 'cookie-form' ); ?>
                <input type="email" name="email" required />
            </label>
            <label>
                <?php esc_html_e( 'Azienda', 'cookie-form' ); ?>
                <input type="text" name="company" required />
            </label>
            <button type="submit" class="devmy-pdf-submit"><?php esc_html_e( 'Invia e Scarica', 'cookie-form' ); ?></button>
            <p class="devmy-pdf-message" aria-live="polite"></p>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    public function handle_form_submission() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        $name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $email       = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $company     = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '';
        $source      = isset( $_POST['source'] ) ? esc_url_raw( wp_unslash( $_POST['source'] ) ) : '';
        $requested   = isset( $_POST['requested_pdf'] ) ? esc_url_raw( wp_unslash( $_POST['requested_pdf'] ) ) : '';
        $ip_address  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $user_agent  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        if ( empty( $name ) || empty( $email ) || empty( $company ) ) {
            wp_send_json_error( array( 'message' => __( 'Compila tutti i campi obbligatori.', 'cookie-form' ) ), 400 );
        }

        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => __( 'Inserisci un indirizzo email valido.', 'cookie-form' ) ), 400 );
        }

        $post_id = wp_insert_post(
            array(
                'post_type'   => 'cookie_form_lead',
                'post_status' => 'private',
                'post_title'  => sprintf( '%s - %s', $name, wp_date( 'Y-m-d H:i:s' ) ),
            ),
            true
        );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Impossibile salvare il contatto. Riprova.', 'cookie-form' ) ), 500 );
        }

        update_post_meta( $post_id, 'name', $name );
        update_post_meta( $post_id, 'email', $email );
        update_post_meta( $post_id, 'company', $company );
        update_post_meta( $post_id, 'source_url', $source );
        update_post_meta( $post_id, 'requested_pdf', $requested );
        update_post_meta( $post_id, 'ip_address', $ip_address );
        update_post_meta( $post_id, 'user_agent', $user_agent );

        $cookie_expire = time() + ( DAY_IN_SECONDS * 365 );
        setcookie( self::COOKIE_NAME, '1', $cookie_expire, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

        if ( defined( 'SITECOOKIEPATH' ) && SITECOOKIEPATH !== COOKIEPATH ) {
            setcookie( self::COOKIE_NAME, '1', $cookie_expire, SITECOOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        }

        wp_send_json_success( array( 'message' => __( 'Lead salvato e download sbloccato.', 'cookie-form' ) ) );
    }
}

register_activation_hook( __FILE__, array( 'Cookie_Form_PDF_Gate', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Cookie_Form_PDF_Gate', 'deactivate' ) );

new Cookie_Form_PDF_Gate();
