<?php
/**
 * Plugin Name: Cookie Form Download Gate
 * Description: Blocca il primo download PDF con form (nome, email, azienda) e sblocca i successivi tramite cookie.
 * Version: 1.3.0
 * Author: Fabrizio @devmy
 * License: GPL2+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cookie_Form_PDF_Gate {
    const NONCE_ACTION = 'cookie_form_pdf_gate';
    const COOKIE_NAME  = 'cookie_form_pdf_gate_unlocked';
    const LEAD_TOKEN   = 'cookie_form_pdf_gate_lead_token';

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
        add_action( 'wp_ajax_cookie_form_track_pdf_download', array( $this, 'handle_download_tracking' ) );
        add_action( 'wp_ajax_nopriv_cookie_form_track_pdf_download', array( $this, 'handle_download_tracking' ) );
        add_action( 'wp_ajax_devmy_track_pdf_download', array( $this, 'handle_download_tracking' ) );
        add_action( 'wp_ajax_nopriv_devmy_track_pdf_download', array( $this, 'handle_download_tracking' ) );

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
            '1.3.0'
        );

        wp_register_script(
            'cookie-form-pdf-gate',
            $base_url . 'assets/js/cookie-form.js',
            array( 'jquery' ),
            '1.3.0',
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
                $requested_pdf  = (string) get_post_meta( $post_id, 'requested_pdf', true );
                $downloaded_pdfs = $this->get_pdf_list_from_meta( $post_id );
                $download_events = $this->get_download_events_from_meta( $post_id );
                $tracked_pdfs   = $this->get_tracked_pdf_list( $downloaded_pdfs, $download_events );
                $primary_pdf    = $this->get_primary_pdf_for_lead( $post_id, $requested_pdf, $tracked_pdfs, $download_events );
                $primary_key    = $this->get_pdf_key( $primary_pdf );

                if ( ! $primary_pdf && empty( $tracked_pdfs ) ) {
                    echo '-';
                    break;
                }

                if ( $primary_pdf ) {
                    echo $this->render_pdf_link( $primary_pdf ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }

                $other_downloads = array();
                foreach ( $tracked_pdfs as $pdf_url ) {
                    if ( ! $pdf_url || $this->get_pdf_key( $pdf_url ) === $primary_key ) {
                        continue;
                    }
                    $other_downloads[] = $pdf_url;
                }

                if ( ! empty( $other_downloads ) ) {
                    echo '<br /><small>' . esc_html__( 'Altri download:', 'cookie-form' ) . ' ';
                    foreach ( $other_downloads as $index => $pdf_url ) {
                        if ( $index > 0 ) {
                            echo ', ';
                        }
                        echo $this->render_pdf_link( $pdf_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }
                    echo '</small>';
                }
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
                'PDF principale',
                'Altri PDF scaricati',
                'Totale download',
                'IP address',
                'User agent',
                'Data invio',
            )
        );

        foreach ( $leads as $lead_id ) {
            $requested_pdf  = (string) get_post_meta( $lead_id, 'requested_pdf', true );
            $downloaded     = $this->get_pdf_list_from_meta( $lead_id );
            $events         = $this->get_download_events_from_meta( $lead_id );
            $tracked_pdfs   = $this->get_tracked_pdf_list( $downloaded, $events );
            $primary_pdf    = $this->get_primary_pdf_for_lead( $lead_id, $requested_pdf, $tracked_pdfs, $events );
            $primary_key    = $this->get_pdf_key( $primary_pdf );
            $other_download = array();

            foreach ( $tracked_pdfs as $pdf_url ) {
                if ( ! $pdf_url || $this->get_pdf_key( $pdf_url ) === $primary_key ) {
                    continue;
                }
                $other_download[] = $pdf_url;
            }

            $total_downloads = count( $events );
            if ( 0 === $total_downloads && ! empty( $tracked_pdfs ) ) {
                $total_downloads = count( $tracked_pdfs );
            }

            fputcsv(
                $output,
                array(
                    (string) get_post_meta( $lead_id, 'name', true ),
                    (string) get_post_meta( $lead_id, 'email', true ),
                    (string) get_post_meta( $lead_id, 'company', true ),
                    (string) get_post_meta( $lead_id, 'source_url', true ),
                    $primary_pdf,
                    implode( ' | ', $other_download ),
                    (string) $total_downloads,
                    (string) get_post_meta( $lead_id, 'ip_address', true ),
                    (string) get_post_meta( $lead_id, 'user_agent', true ),
                    (string) get_the_date( 'Y-m-d H:i:s', $lead_id ),
                )
            );
        }

        fclose( $output );
        exit;
    }

    private function get_download_events_from_meta( $lead_id ) {
        $events = get_post_meta( $lead_id, 'download_events', true );
        if ( ! is_array( $events ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $events as $event ) {
            if ( ! is_array( $event ) ) {
                continue;
            }

            $pdf_url = isset( $event['pdf'] ) ? $this->normalize_pdf_url( $event['pdf'] ) : '';
            $type    = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';

            if ( ! $pdf_url ) {
                continue;
            }

            $normalized[] = array(
                'pdf'        => $pdf_url,
                'source'     => isset( $event['source'] ) ? esc_url_raw( (string) $event['source'] ) : '',
                'type'       => $type ? $type : 'followup',
                'tracked_at' => isset( $event['tracked_at'] ) ? sanitize_text_field( (string) $event['tracked_at'] ) : '',
            );
        }

        return $normalized;
    }

    private function get_pdf_list_from_meta( $lead_id ) {
        $pdfs = get_post_meta( $lead_id, 'downloaded_pdfs', true );
        if ( ! is_array( $pdfs ) ) {
            return array();
        }

        $clean = array();
        $keys  = array();
        foreach ( $pdfs as $pdf_url ) {
            $url = $this->normalize_pdf_url( $pdf_url );
            $key = $this->get_pdf_key( $url );
            if ( ! $url || ! $key || in_array( $key, $keys, true ) ) {
                continue;
            }
            $clean[] = $url;
            $keys[]  = $key;
        }

        return $clean;
    }

    private function get_tracked_pdf_list( $downloaded_pdfs, $download_events ) {
        $tracked = array();
        $keys    = array();

        if ( is_array( $downloaded_pdfs ) ) {
            foreach ( $downloaded_pdfs as $pdf_url ) {
                $url = $this->normalize_pdf_url( $pdf_url );
                $key = $this->get_pdf_key( $url );
                if ( ! $url || ! $key || in_array( $key, $keys, true ) ) {
                    continue;
                }
                $tracked[] = $url;
                $keys[]    = $key;
            }
        }

        if ( is_array( $download_events ) ) {
            foreach ( $download_events as $event ) {
                if ( ! is_array( $event ) || empty( $event['pdf'] ) ) {
                    continue;
                }

                $url = $this->normalize_pdf_url( $event['pdf'] );
                $key = $this->get_pdf_key( $url );
                if ( ! $url || ! $key || in_array( $key, $keys, true ) ) {
                    continue;
                }
                $tracked[] = $url;
                $keys[]    = $key;
            }
        }

        return $tracked;
    }

    private function get_primary_pdf_for_lead( $lead_id, $requested_pdf = '', $tracked_pdfs = array(), $download_events = array() ) {
        $requested_pdf = $this->normalize_pdf_url( $requested_pdf );
        if ( $requested_pdf ) {
            return $requested_pdf;
        }

        if ( empty( $download_events ) ) {
            $download_events = $this->get_download_events_from_meta( $lead_id );
        }

        if ( is_array( $download_events ) ) {
            foreach ( $download_events as $event ) {
                if ( ! is_array( $event ) || empty( $event['pdf'] ) ) {
                    continue;
                }

                $event_type = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';
                if ( 'unlock' !== $event_type ) {
                    continue;
                }

                $url = $this->normalize_pdf_url( $event['pdf'] );
                if ( $url ) {
                    return $url;
                }
            }

            foreach ( $download_events as $event ) {
                if ( ! is_array( $event ) || empty( $event['pdf'] ) ) {
                    continue;
                }

                $url = $this->normalize_pdf_url( $event['pdf'] );
                if ( $url ) {
                    return $url;
                }
            }
        }

        if ( empty( $tracked_pdfs ) ) {
            $tracked_pdfs = $this->get_pdf_list_from_meta( $lead_id );
        }

        if ( is_array( $tracked_pdfs ) ) {
            foreach ( $tracked_pdfs as $pdf_url ) {
                $url = $this->normalize_pdf_url( $pdf_url );
                if ( $url ) {
                    return $url;
                }
            }
        }

        return '';
    }

    private function render_pdf_link( $pdf_url ) {
        $path     = wp_parse_url( $pdf_url, PHP_URL_PATH );
        $pdf_name = $path ? wp_basename( $path ) : $pdf_url;

        return sprintf(
            '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
            esc_url( $pdf_url ),
            esc_html( $pdf_name )
        );
    }

    private function append_download_event( $lead_id, $requested_pdf, $source, $event_type ) {
        $lead_id       = absint( $lead_id );
        $requested_pdf = $this->normalize_pdf_url( $requested_pdf );
        $source        = esc_url_raw( $source );
        $event_type    = sanitize_key( $event_type );

        if ( ! $lead_id || ! $requested_pdf ) {
            return;
        }

        $events = get_post_meta( $lead_id, 'download_events', true );
        if ( ! is_array( $events ) ) {
            $events = array();
        }

        $events[] = array(
            'pdf'        => $requested_pdf,
            'source'     => $source,
            'type'       => $event_type ? $event_type : 'followup',
            'tracked_at' => wp_date( 'Y-m-d H:i:s' ),
        );

        update_post_meta( $lead_id, 'download_events', $events );

        $downloaded_pdfs = $this->get_pdf_list_from_meta( $lead_id );
        $requested_key = $this->get_pdf_key( $requested_pdf );
        $exists        = false;

        foreach ( $downloaded_pdfs as $existing_pdf ) {
            if ( $this->get_pdf_key( $existing_pdf ) === $requested_key ) {
                $exists = true;
                break;
            }
        }

        if ( ! $exists ) {
            $downloaded_pdfs[] = $requested_pdf;
            update_post_meta( $lead_id, 'downloaded_pdfs', $downloaded_pdfs );
        }
    }

    private function normalize_pdf_url( $pdf_url ) {
        $url = esc_url_raw( (string) $pdf_url );
        if ( ! $url ) {
            return '';
        }

        // Convert relative paths to absolute so comparisons remain stable.
        if ( '/' === substr( $url, 0, 1 ) ) {
            $url = home_url( $url );
        }

        return $url;
    }

    private function get_pdf_key( $pdf_url ) {
        $url  = $this->normalize_pdf_url( $pdf_url );
        $path = wp_parse_url( $url, PHP_URL_PATH );

        if ( ! $path ) {
            return '';
        }

        return ltrim( strtolower( rawurldecode( $path ) ), '/' );
    }

    private function create_lead_token( $lead_id ) {
        $lead_id = absint( $lead_id );
        if ( ! $lead_id ) {
            return '';
        }

        $signature = hash_hmac( 'sha256', (string) $lead_id, wp_salt( 'auth' ) );
        return base64_encode( $lead_id . ':' . $signature );
    }

    private function get_lead_id_from_token( $lead_token ) {
        $decoded = base64_decode( (string) $lead_token, true );
        if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
            return 0;
        }

        $parts = explode( ':', $decoded, 2 );
        if ( 2 !== count( $parts ) ) {
            return 0;
        }

        $lead_id   = absint( $parts[0] );
        $signature = sanitize_text_field( $parts[1] );

        if ( ! $lead_id || ! $signature ) {
            return 0;
        }

        $expected = hash_hmac( 'sha256', (string) $lead_id, wp_salt( 'auth' ) );
        if ( ! hash_equals( $expected, $signature ) ) {
            return 0;
        }

        return $lead_id;
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
                'leadTokenKey' => self::LEAD_TOKEN,
                'validation'  => array(
                    'nameRequired'    => __( 'Inserisci il nome.', 'cookie-form' ),
                    'emailRequired'   => __( 'Inserisci l\'email.', 'cookie-form' ),
                    'emailInvalid'    => __( 'Inserisci un indirizzo email valido.', 'cookie-form' ),
                    'companyRequired' => __( 'Inserisci il nome dell\'azienda.', 'cookie-form' ),
                    'pdfRequired'     => __( 'Non riesco a capire quale PDF scaricare. Chiudi il popup e riprova dal pulsante download.', 'cookie-form' ),
                    'nonceError'      => __( 'Sessione scaduta. Ricarica la pagina e riprova.', 'cookie-form' ),
                    'genericError'    => __( 'Si e verificato un errore. Riprova.', 'cookie-form' ),
                ),
                'errorText'   => __( 'Si e verificato un errore. Riprova.', 'cookie-form' ),
                'successText' => __( 'Grazie! Download sbloccato.', 'cookie-form' ),
            )
        );
    }

    private function validate_submission_fields( $name, $email, $company, $requested_pdf ) {
        $field_errors = array();

        if ( '' === $name ) {
            $field_errors['name'] = __( 'Inserisci il nome.', 'cookie-form' );
        }

        if ( '' === $email ) {
            $field_errors['email'] = __( 'Inserisci l\'email.', 'cookie-form' );
        } elseif ( ! is_email( $email ) ) {
            $field_errors['email'] = __( 'Inserisci un indirizzo email valido.', 'cookie-form' );
        }

        if ( '' === $company ) {
            $field_errors['company'] = __( 'Inserisci il nome dell\'azienda.', 'cookie-form' );
        }

        if ( '' === $requested_pdf ) {
            $field_errors['requested_pdf'] = __( 'Non riesco a capire quale PDF scaricare. Chiudi il popup e riprova dal pulsante download.', 'cookie-form' );
        }

        return $field_errors;
    }

    private function get_first_field_error_message( $field_errors ) {
        foreach ( array( 'name', 'email', 'company', 'requested_pdf' ) as $field_name ) {
            if ( isset( $field_errors[ $field_name ] ) && is_string( $field_errors[ $field_name ] ) ) {
                return $field_errors[ $field_name ];
            }
        }

        return __( 'Compila correttamente i campi richiesti.', 'cookie-form' );
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
        if ( false === check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Sessione scaduta. Ricarica la pagina e riprova.', 'cookie-form' ),
                    'code'    => 'invalid_nonce',
                ),
                403
            );
        }

        $name        = isset( $_POST['name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) : '';
        $email       = isset( $_POST['email'] ) ? trim( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : '';
        $company     = isset( $_POST['company'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['company'] ) ) ) : '';
        $source      = isset( $_POST['source'] ) ? esc_url_raw( wp_unslash( $_POST['source'] ) ) : '';
        $requested   = isset( $_POST['requested_pdf'] ) ? esc_url_raw( wp_unslash( $_POST['requested_pdf'] ) ) : '';
        $ip_address  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $user_agent  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        $field_errors = $this->validate_submission_fields( $name, $email, $company, $requested );
        if ( ! empty( $field_errors ) ) {
            wp_send_json_error(
                array(
                    'message'     => $this->get_first_field_error_message( $field_errors ),
                    'fieldErrors' => $field_errors,
                    'field_errors'=> $field_errors,
                    'code'        => 'validation_error',
                ),
                400
            );
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
        $this->append_download_event( $post_id, $requested, $source, 'unlock' );

        $cookie_expire = time() + ( DAY_IN_SECONDS * 365 );
        setcookie( self::COOKIE_NAME, '1', $cookie_expire, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

        if ( defined( 'SITECOOKIEPATH' ) && SITECOOKIEPATH !== COOKIEPATH ) {
            setcookie( self::COOKIE_NAME, '1', $cookie_expire, SITECOOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        }

        wp_send_json_success(
            array(
                'message'   => __( 'Lead salvato e download sbloccato.', 'cookie-form' ),
                'leadToken' => $this->create_lead_token( $post_id ),
            )
        );
    }

    public function handle_download_tracking() {
        if ( false === check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Sessione scaduta. Ricarica la pagina e riprova.', 'cookie-form' ),
                    'code'    => 'invalid_nonce',
                ),
                403
            );
        }

        $lead_token  = isset( $_POST['lead_token'] ) ? sanitize_text_field( wp_unslash( $_POST['lead_token'] ) ) : '';
        $requested   = isset( $_POST['requested_pdf'] ) ? esc_url_raw( wp_unslash( $_POST['requested_pdf'] ) ) : '';
        $source      = isset( $_POST['source'] ) ? esc_url_raw( wp_unslash( $_POST['source'] ) ) : '';
        $lead_id     = $this->get_lead_id_from_token( $lead_token );

        if ( ! $lead_id || 'cookie_form_lead' !== get_post_type( $lead_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Lead non valido.', 'cookie-form' ) ), 400 );
        }

        if ( ! $requested ) {
            wp_send_json_error( array( 'message' => __( 'PDF non valido.', 'cookie-form' ) ), 400 );
        }

        $this->append_download_event( $lead_id, $requested, $source, 'followup' );

        wp_send_json_success( array( 'message' => __( 'Download tracciato.', 'cookie-form' ) ) );
    }
}

register_activation_hook( __FILE__, array( 'Cookie_Form_PDF_Gate', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Cookie_Form_PDF_Gate', 'deactivate' ) );

new Cookie_Form_PDF_Gate();
