<?php
/**
 * Plugin Name: Content Quality Guard
 * Plugin URI: https://github.com/elizarov-n906/content-quality-guard
 * Description: Подсчет кириллических символов, авто-черновик при малом объеме и гибкий вывод бейджа в архивах.
 * Version: 1.5.0
 * Author: Elizarov_n906
 * Author URI: https://github.com/elizarov-n906
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: content-quality-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CQG_Plugin {
    private static $instance = null;
    private $option_name = 'cqg_settings';
    private $menu_slug = 'cqg-settings';

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        
        add_action( 'save_post', array( $this, 'check_and_draft' ), 10, 3 );
        
        add_filter( 'manage_posts_columns', array( $this, 'add_column' ) );
        add_filter( 'manage_pages_columns', array( $this, 'add_column' ) );
        add_action( 'manage_posts_custom_column', array( $this, 'fill_column' ), 10, 2 );
        add_action( 'manage_pages_custom_column', array( $this, 'fill_column' ), 10, 2 );
        add_filter( 'manage_edit-post_sortable_columns', array( $this, 'sortable_column' ) );
        add_action( 'pre_get_posts', array( $this, 'sort_query' ) );
        
        add_action( 'edit_form_after_title', array( $this, 'show_editor_notice' ) );
        add_action( 'admin_notices', array( $this, 'admin_notice' ) );
        
        // Хуки для фронтенда (работают только если включено в настройках)
        add_filter( 'the_title', array( $this, 'append_badge_to_title' ), 10, 2 );
        add_filter( 'the_content', array( $this, 'add_frontend_badge' ), 5 );
        add_filter( 'the_excerpt', array( $this, 'add_frontend_badge' ), 5 );
        add_shortcode( 'cqg_badge', array( $this, 'render_badge_shortcode' ) );
        
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );
    }

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    public function add_settings_link( $links ) {
        $links[] = '<a href="' . admin_url( 'index.php?page=' . $this->menu_slug ) . '">' . esc_html__( 'Настройки', 'content-quality-guard' ) . '</a>';
        return $links;
    }

    public function add_admin_menu() {
        add_submenu_page( 
            'index.php', 
            esc_html__( 'Качество контента', 'content-quality-guard' ), 
            esc_html__( 'Качество контента', 'content-quality-guard' ), 
            'manage_options', 
            $this->menu_slug, 
            array( $this, 'render_settings' ) 
        );
    }

    public function register_settings() {
        register_setting( $this->option_name, $this->option_name, array( $this, 'sanitize' ) );
    }

    public function sanitize( $input ) {
        $out = array();
        $out['min_cyrillic'] = isset( $input['min_cyrillic'] ) ? max( 0, intval( $input['min_cyrillic'] ) ) : 100;
        // Новая настройка: включение/выключение вывода на фронте
        $out['enable_frontend_badge'] = isset( $input['enable_frontend_badge'] ) ? 1 : 0;
        return $out;
    }

    public function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $opts = get_option( $this->option_name, array( 
            'min_cyrillic' => 100, 
            'enable_frontend_badge' => 1 
        ) );

        $message = '';
        if ( isset( $_POST['cqg_recount'] ) && check_admin_referer( 'cqg_recount_nonce' ) ) {
            $posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids' ) );
            foreach ( $posts as $pid ) {
                $p = get_post( $pid );
                if ( $p ) update_post_meta( $pid, '_cyrillic_char_count', intval( $this->count_cyrillic( $p->post_title . ' ' . $p->post_content ) ) );
            }
            $message = '<div class="notice notice-success"><p>Пересчет завершен.</p></div>';
        }
        ?>
        <style>
            .elizarov_n906 { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
            .elizarov_n906 .custom-container { max-width: 900px; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-top: 20px; border: 1px solid #c3c4c7; }
            .elizarov_n906 h1 { margin-top: 0; font-size: 23px; font-weight: 600; }
            .elizarov_n906 table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            .elizarov_n906 th, .elizarov_n906 td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
            .elizarov_n906 th { background: #f6f7f7; font-weight: 600; color: #1d2327; }
            .elizarov_n906 .critical { background-color: #ffe6e6 !important; color: #d63638; font-weight: bold; }
            .elizarov_n906 .warning { background-color: #fff8e5 !important; color: #dba617; font-weight: bold; }
            .elizarov_n906 .stats-box { background: #f0f6fc; border: 1px solid #c5d9ed; padding: 15px; border-radius: 4px; margin-top: 20px; }
            .elizarov_n906 .stats-box ul { margin: 10px 0 0 20px; padding: 0; }
            .elizarov_n906 .stats-box li { margin-bottom: 5px; }
            .elizarov_n906 .custom-btn { display: inline-block; padding: 10px 20px; background: #2271b1; color: white !important; text-decoration: none; border-radius: 4px; font-weight: 500; border: none; cursor: pointer; font-size: 14px; margin-top: 20px; transition: background 0.2s; }
            .elizarov_n906 .custom-btn:hover { background: #135e96; }
            .elizarov_n906 .author-footer { margin-top: 40px; color: #666; font-size: 13px; border-top: 1px solid #ddd; padding-top: 10px; }
        </style>
        <div class="wrap elizarov_n906">
            <div class="custom-container">
                <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
                <?php echo $message; ?>
                <form method="post" action="options.php">
                    <?php settings_fields( $this->option_name ); ?>
                    <table>
                        <tr>
                            <th><label for="min_cyrillic">Минимум кириллических символов</label></th>
                            <td>
                                <input type="number" id="min_cyrillic" name="<?php echo esc_attr( $this->option_name ); ?>[min_cyrillic]" value="<?php echo esc_attr( $opts['min_cyrillic'] ); ?>" class="regular-text">
                                <p class="description">Если в записи меньше этого количества кириллических букв, она автоматически станет черновиком.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="enable_frontend_badge">Вывод бейджа на сайте</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_frontend_badge]" value="1" <?php checked( 1, $opts['enable_frontend_badge'] ); ?>>
                                    Включить отображение количества символов под заголовками в архивах и списках
                                </label>
                                <p class="description">Снимите галочку, чтобы скрыть бейджи с фронтенда, оставив функционал только для админки.</p>
                            </td>
                        </tr>
                    </table>
                    <button type="submit" class="custom-btn">Сохранить настройки</button>
                </form>
                <div class="stats-box">
                    <strong>Массовые операции</strong>
                    <form method="post" style="margin-top:10px;">
                        <?php wp_nonce_field( 'cqg_recount_nonce' ); ?>
                        <button type="submit" name="cqg_recount" class="custom-btn">Пересчитать кириллицу во всех записях</button>
                    </form>
                </div>
                <div class="author-footer">Автор плагина: elizarov_n906</div>
            </div>
        </div>
        <?php
    }

    private function count_cyrillic( $text ) {
        if ( empty( $text ) ) return 0;
        preg_match_all( '/\p{Cyrillic}/u', wp_strip_all_tags( strip_shortcodes( $text ) ), $m );
        return isset( $m[0] ) ? count( $m[0] ) : 0;
    }

    public function check_and_draft( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) return;
        if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) return;

        $opts = get_option( $this->option_name, array( 'min_cyrillic' => 100 ) );
        $count = $this->count_cyrillic( $post->post_title . ' ' . $post->post_content );
        update_post_meta( $post_id, '_cyrillic_char_count', intval( $count ) );

        if ( $count < $opts['min_cyrillic'] && ! in_array( $post->post_status, array( 'draft', 'trash' ), true ) ) {
            remove_action( 'save_post', array( $this, 'check_and_draft' ), 10 );
            wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
            add_action( 'save_post', array( $this, 'check_and_draft' ), 10, 3 );
            set_transient( 'cqg_draft_notice_' . get_current_user_id(), $post_id, 60 );
        }
    }

    public function add_column( $columns ) { 
        $columns['cqg_cyrillic'] = 'Кириллица'; 
        return $columns; 
    }

    public function fill_column( $column, $post_id ) {
        if ( 'cqg_cyrillic' !== $column ) return;
        $count = get_post_meta( $post_id, '_cyrillic_char_count', true );
        if ( '' === $count ) {
            $p = get_post( $post_id );
            if ( $p ) $count = $this->count_cyrillic( $p->post_title . ' ' . $p->post_content );
        }
        $c = intval( $count );
        $color = $c < 100 ? '#c00' : ( $c < 200 ? '#0055cc' : '#1f7a1f' );
        echo '<strong style="color:' . esc_attr( $color ) . ';">' . esc_html( $c ) . '</strong>';
    }

    public function sortable_column( $columns ) { 
        $columns['cqg_cyrillic'] = 'cqg_cyrillic'; 
        return $columns; 
    }

    public function sort_query( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() || 'cqg_cyrillic' !== $query->get( 'orderby' ) ) return;
        $query->set( 'meta_key', '_cyrillic_char_count' );
        $query->set( 'orderby', 'meta_value_num' );
    }

    public function show_editor_notice( $post ) {
        if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) return;
        $count = $this->count_cyrillic( $post->post_title . ' ' . $post->post_content );
        $opts = get_option( $this->option_name, array( 'min_cyrillic' => 100 ) );
        $color = $count < $opts['min_cyrillic'] ? '#c00' : '#1f7a1f';
        $warn = $count < $opts['min_cyrillic'] ? ' <span style="color:#c00;">(будет переведено в черновик!)</span>' : '';
        echo '<div style="margin:10px 0; padding:8px 12px; background:#f9f9f9; border-left:4px solid ' . esc_attr( $color ) . ';">
              <strong>Кириллических символов:</strong> <span style="color:' . esc_attr( $color ) . '; font-size:16px; font-weight:700;">' . esc_html( $count ) . '</span>' . $warn . '</div>';
    }

    public function admin_notice() {
        $pid = get_transient( 'cqg_draft_notice_' . get_current_user_id() );
        if ( ! $pid ) return;
        delete_transient( 'cqg_draft_notice_' . get_current_user_id() );
        echo '<div class="notice notice-warning is-dismissible"><p><strong>Запись переведена в черновик!</strong> Недостаточно кириллических символов. <a href="' . esc_url( get_edit_post_link( $pid ) ) . '">Редактировать</a></p></div>';
    }

    /**
     * Проверка: включен ли вывод на фронте
     */
    private function is_frontend_enabled() {
        $opts = get_option( $this->option_name, array( 'enable_frontend_badge' => 1 ) );
        return (bool) $opts['enable_frontend_badge'];
    }

    /**
     * Основной метод: добавление бейджа к заголовку в архивах
     */
    public function append_badge_to_title( $title, $id ) {
        if ( ! $this->is_frontend_enabled() ) return $title;
        if ( is_admin() || is_feed() || is_singular() || is_404() ) return $title;

        $post_type = get_post_type( $id );
        if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) return $title;

        $count = get_post_meta( $id, '_cyrillic_char_count', true );
        if ( '' === $count || $count === false ) {
            $post_obj = get_post( $id );
            if ( $post_obj ) {
                $count = $this->count_cyrillic( $post_obj->post_title . ' ' . $post_obj->post_content );
                update_post_meta( $id, '_cyrillic_char_count', intval( $count ) );
            } else {
                return $title;
            }
        }
        $count = intval( $count );

        if ( $count < 100 ) {
            $bg = '#ffe5e5'; $color = '#c00'; $border = '#f5c2c2';
        } elseif ( $count < 200 ) {
            $bg = '#e5f0ff'; $color = '#0055cc'; $border = '#b3d1ff';
        } else {
            $bg = '#e6f7e5'; $color = '#1f7a1f'; $border = '#b5dcb3';
        }

        $badge = sprintf(
            '<span class="cqg-cyrillic-badge" style="display:table; background:%s; color:%s; border:1px solid %s; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:700; margin-top:6px; font-family: sans-serif; vertical-align: middle;">%d символов</span>',
            esc_attr( $bg ), esc_attr( $color ), esc_attr( $border ), $count
        );

        return $title . $badge;
    }

    /**
     * Резервный метод: добавление бейджа к контенту/анонсу
     */
    public function add_frontend_badge( $content ) {
        if ( ! $this->is_frontend_enabled() ) return $content;
        if ( is_admin() || is_feed() || is_singular() ) return $content;
        if ( strpos( $content, 'cqg-cyrillic-badge' ) !== false ) return $content;

        $post_id = get_the_ID();
        if ( ! $post_id ) {
            global $post;
            if ( isset( $post->ID ) ) $post_id = $post->ID;
            else return $content;
        }

        $count = get_post_meta( $post_id, '_cyrillic_char_count', true );
        if ( '' === $count || $count === false ) {
            $post_obj = get_post( $post_id );
            if ( $post_obj ) {
                $count = $this->count_cyrillic( $post_obj->post_title . ' ' . $post_obj->post_content );
                update_post_meta( $post_id, '_cyrillic_char_count', intval( $count ) );
            } else {
                return $content;
            }
        }
        $count = intval( $count );

        if ( $count < 100 ) {
            $bg = '#ffe5e5'; $color = '#c00'; $border = '#f5c2c2';
        } elseif ( $count < 200 ) {
            $bg = '#e5f0ff'; $color = '#0055cc'; $border = '#b3d1ff';
        } else {
            $bg = '#e6f7e5'; $color = '#1f7a1f'; $border = '#b5dcb3';
        }

        $badge = sprintf(
            '<div class="cqg-cyrillic-badge" style="display:table; background:%s; color:%s; border:1px solid %s; padding:4px 10px; border-radius:4px; font-size:13px; font-weight:700; margin-bottom:8px; font-family: sans-serif;">%d символов</div>',
            esc_attr( $bg ), esc_attr( $color ), esc_attr( $border ), $count
        );

        return $badge . $content;
    }

    /**
     * Шорткод для ручного размещения: [cqg_badge]
     */
    public function render_badge_shortcode( $atts ) {
        if ( ! $this->is_frontend_enabled() ) return '';
        
        $post_id = get_the_ID();
        if ( ! $post_id ) return '';

        $count = get_post_meta( $post_id, '_cyrillic_char_count', true );
        if ( '' === $count || $count === false ) {
            $post_obj = get_post( $post_id );
            if ( $post_obj ) {
                $count = $this->count_cyrillic( $post_obj->post_title . ' ' . $post_obj->post_content );
            } else {
                return '';
            }
        }
        $count = intval( $count );

        if ( $count < 100 ) {
            $bg = '#ffe5e5'; $color = '#c00'; $border = '#f5c2c2';
        } elseif ( $count < 200 ) {
            $bg = '#e5f0ff'; $color = '#0055cc'; $border = '#b3d1ff';
        } else {
            $bg = '#e6f7e5'; $color = '#1f7a1f'; $border = '#b5dcb3';
        }

        return sprintf(
            '<span class="cqg-cyrillic-badge" style="display:table; background:%s; color:%s; border:1px solid %s; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:700; font-family: sans-serif;">%d символов</span>',
            esc_attr( $bg ), esc_attr( $color ), esc_attr( $border ), $count
        );
    }
}
CQG_Plugin::get_instance();