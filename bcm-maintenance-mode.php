<?php
/**
 * Plugin Name:       BCM Maintenance Mode
 * Plugin URI:        https://github.com/cirobrandao/bcm-maintenance-mode
 * Description:       Blocks site front-end for non-admin visitors. Only logged-in administrators can view the site. Shows a customizable maintenance/development page to everyone else.
 * Version:           0.5.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            z/ONE
 * Author URI:        https://dev.zone.net.br/wordpress
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bcm-maintenance-mode
 */

if (!defined('ABSPATH')) exit;

final class BCM_Maintenance_Mode {
  private const OPT_KEY = 'bcm_mm_settings';

  public function hooks(): void {
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'register_settings']);

    // Admin-bar switcher (single source of truth for status switching)
    add_action('admin_init', [$this, 'handle_adminbar_switch']);
    add_action('admin_bar_menu', [$this, 'admin_bar_notice'], 100);

    add_action('template_redirect', [$this, 'maybe_block_frontend'], 0);
  }

  public static function defaults(): array {
    return [
      'enabled' => 0,
      'mode' => 'maintenance', // maintenance|development

      // Maintenance template
      'title_maintenance' => 'Site em manutenção',
      'message_maintenance' => 'Estamos realizando manutenção. Tente novamente mais tarde.',

      // Development template
      'title_development' => 'Site em desenvolvimento',
      'message_development' => 'Este ambiente está em desenvolvimento e temporariamente indisponível para visitantes.',
    ];
  }

  private function get_settings_for_blog(int $blog_id): array {
    // Multisite: per-site option. Single-site: fall back to regular option.
    if (function_exists('get_blog_option') && is_multisite()) {
      $raw = get_blog_option($blog_id, self::OPT_KEY, []);
    } else {
      $raw = get_option(self::OPT_KEY, []);
    }

    if (!is_array($raw)) $raw = [];
    return array_merge(self::defaults(), $raw);
  }

  private function update_settings_for_blog(int $blog_id, array $settings): void {
    if (function_exists('update_blog_option') && is_multisite()) {
      update_blog_option($blog_id, self::OPT_KEY, $settings);
    } else {
      update_option(self::OPT_KEY, $settings);
    }
  }

  public function get_settings(): array {
    return $this->get_settings_for_blog(get_current_blog_id());
  }

  private function set_mode_for_blog(int $blog_id, string $mode): void {
    $s = $this->get_settings_for_blog($blog_id);

    if ($mode === 'online') {
      $s['enabled'] = 0;
      // keep last template mode saved
    } else {
      $mode = sanitize_key($mode);
      $s['mode'] = in_array($mode, ['maintenance', 'development'], true) ? $mode : 'maintenance';
      $s['enabled'] = 1;
    }

    $this->update_settings_for_blog($blog_id, $s);
  }

  public function register_settings(): void {
    register_setting('bcm_mm', self::OPT_KEY, [
      'type' => 'array',
      'sanitize_callback' => [$this, 'sanitize'],
      'default' => self::defaults(),
    ]);
  }

  public function sanitize($input): array {
    $in = is_array($input) ? $input : [];
    $out = self::defaults();

    $out['enabled'] = !empty($in['enabled']) ? 1 : 0;

    $mode = sanitize_key((string)($in['mode'] ?? $out['mode']));
    $out['mode'] = in_array($mode, ['maintenance', 'development'], true) ? $mode : 'maintenance';

    $out['title_maintenance'] = sanitize_text_field((string)($in['title_maintenance'] ?? $out['title_maintenance']));
    $out['title_development'] = sanitize_text_field((string)($in['title_development'] ?? $out['title_development']));

    // Keep it flexible: allow basic HTML.
    $out['message_maintenance'] = wp_kses_post((string)($in['message_maintenance'] ?? $out['message_maintenance']));
    $out['message_development'] = wp_kses_post((string)($in['message_development'] ?? $out['message_development']));

    return $out;
  }

  public function admin_menu(): void {
    add_options_page(
      'BCM Maintenance Mode',
      'BCM Maintenance',
      'manage_options',
      'bcm-maintenance-mode',
      [$this, 'render_settings']
    );
  }

  private function current_mode_label(array $s): array {
    if (empty($s['enabled'])) {
      return ['Online', '#22c55e'];
    }

    if (($s['mode'] ?? 'maintenance') === 'development') {
      return ['Desenvolvimento', '#f59e0b'];
    }

    return ['Manutenção', '#ef4444'];
  }

  private function adminbar_switch_url(string $mode): string {
    $url = add_query_arg([
      'bcm_mm_set' => $mode,
      'bcm_mm_blog' => (string) get_current_blog_id(),
    ], admin_url());
    return wp_nonce_url($url, 'bcm_mm_set');
  }

  public function handle_adminbar_switch(): void {
    if (!is_user_logged_in() || !current_user_can('manage_options')) return;
    if (!isset($_GET['bcm_mm_set'])) return;

    check_admin_referer('bcm_mm_set');

    $mode = sanitize_key((string)$_GET['bcm_mm_set']);
    if (!in_array($mode, ['online', 'maintenance', 'development'], true)) {
      return;
    }

    $blog_id = isset($_GET['bcm_mm_blog']) ? (int) $_GET['bcm_mm_blog'] : get_current_blog_id();
    if ($blog_id <= 0) { $blog_id = get_current_blog_id(); }
    $this->set_mode_for_blog($blog_id, $mode);

    wp_safe_redirect(remove_query_arg(['bcm_mm_set', 'bcm_mm_blog', '_wpnonce']));
    exit;
  }

  private function tabs(string $active): void {
    $base = admin_url('options-general.php?page=bcm-maintenance-mode');
    echo '<h2 class="nav-tab-wrapper">';

    $tabs = [
      'info' => __('Informações', 'bcm-maintenance-mode'),
      'settings' => __('Configurações', 'bcm-maintenance-mode'),
    ];

    foreach ($tabs as $key => $label) {
      $url = esc_url(add_query_arg(['tab' => $key], $base));
      $cls = 'nav-tab' . (($active === $key) ? ' nav-tab-active' : '');
      echo '<a class="' . esc_attr($cls) . '" href="' . $url . '">' . esc_html($label) . '</a>';
    }

    echo '</h2>';
  }

  public function render_settings(): void {
    if (!current_user_can('manage_options')) {
      wp_die('Not allowed');
    }

    $s = $this->get_settings();
    [$label, $color] = $this->current_mode_label($s);

    $tab = isset($_GET['tab']) ? sanitize_key((string)$_GET['tab']) : 'info';
    if (!in_array($tab, ['info', 'settings'], true)) {
      $tab = 'info';
    }

    echo '<div class="wrap">';
    echo '<h1>BCM Maintenance</h1>';

    echo '<p style="display:flex;gap:10px;align-items:center">'
      . '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . esc_attr($color) . '"></span>'
      . '<strong>Status:</strong> ' . esc_html($label)
      . '</p>';

    // IMPORTANT: switching status is only via admin bar.
    echo '<p class="description">O status (Online/Manutenção/Desenvolvimento) é alterado apenas pela barra administrativa.</p>';

    $this->tabs($tab);

    if ($tab === 'info') {
      echo '<h2>Sobre</h2>';
      echo '<p>Este plugin bloqueia o acesso ao front-end para visitantes (usuário não-admin). Apenas administradores logados conseguem visualizar o conteúdo.</p>';
      echo '<p><strong>Onde alterar o status:</strong> Barra administrativa (topo) → <code>Mode</code>.</p>';
      echo '<p><strong>Onde personalizar o layout:</strong> Aba <em>Configurações</em> nesta tela.</p>';

      echo '<h2>Atualizações</h2>';
      echo '<p>Atualizações do plugin devem ser aplicadas via deploy/atualização do diretório:</p>';
      echo '<p><code>wp-content/plugins/bcm-maintenance-mode/</code></p>';
      echo '<p>Se houver um repositório Git interno/privado, use-o como fonte de atualização (pull/replace) conforme o fluxo do seu time.</p>';

      echo '<hr>';
      echo '<p><strong>Nota de segurança:</strong> a página de manutenção não exibe link nem redireciona para login.</p>';

      echo '</div>';
      return;
    }

    // settings tab
    $edit_template = isset($_GET['template']) ? sanitize_key((string)$_GET['template']) : 'maintenance';
    if (!in_array($edit_template, ['maintenance', 'development'], true)) {
      $edit_template = 'maintenance';
    }

    echo '<form method="post" action="options.php">';
    settings_fields('bcm_mm');

    echo '<h2>Configurações</h2>';

    echo '<table class="form-table" role="presentation">';

    // enabled toggle stays here (but status switching stays on admin bar)
    echo '<tr><th scope="row">Ativar modo (bloqueio)</th><td>';
    printf('<label><input type="checkbox" name="%s[enabled]" value="1" %s> Ativo</label>', esc_attr(self::OPT_KEY), checked(!empty($s['enabled']), true, false));
    echo '<p class="description">Quando ativo, visitantes verão o template escolhido pelo status atual.</p>';
    echo '</td></tr>';

    // keep mode stored (not used as selector here, but kept for compatibility)
    printf('<input type="hidden" name="%s[mode]" value="%s">', esc_attr(self::OPT_KEY), esc_attr($s['mode']));

    // Template selector (for editing only)
    echo '<tr><th scope="row">Modelo para editar</th><td>';
    $base = admin_url('options-general.php?page=bcm-maintenance-mode&tab=settings');
    echo '<select onchange="window.location=this.value">';
    $u1 = esc_url(add_query_arg(['template' => 'maintenance'], $base));
    $u2 = esc_url(add_query_arg(['template' => 'development'], $base));
    echo '<option value="' . $u1 . '" ' . selected($edit_template, 'maintenance', false) . '>Manutenção</option>';
    echo '<option value="' . $u2 . '" ' . selected($edit_template, 'development', false) . '>Desenvolvimento</option>';
    echo '</select>';
    echo '<p class="description">Selecione qual modelo você quer editar. O status em uso é escolhido na barra administrativa.</p>';
    echo '</td></tr>';

    if ($edit_template === 'development') {
      echo '<tr><th scope="row">Título (Desenvolvimento)</th><td>';
      printf('<input type="text" class="regular-text" name="%s[title_development]" value="%s">', esc_attr(self::OPT_KEY), esc_attr($s['title_development']));
      echo '</td></tr>';

      echo '<tr><th scope="row">Layout/Conteúdo (Desenvolvimento)</th><td>';
      printf('<textarea class="large-text" rows="10" name="%s[message_development]">%s</textarea>', esc_attr(self::OPT_KEY), esc_textarea($s['message_development']));
      echo '<p class="description">Cole aqui o HTML/texto como preferir. O plugin não impõe layout — você personaliza livremente.</p>';
      echo '</td></tr>';
    } else {
      echo '<tr><th scope="row">Título (Manutenção)</th><td>';
      printf('<input type="text" class="regular-text" name="%s[title_maintenance]" value="%s">', esc_attr(self::OPT_KEY), esc_attr($s['title_maintenance']));
      echo '</td></tr>';

      echo '<tr><th scope="row">Layout/Conteúdo (Manutenção)</th><td>';
      printf('<textarea class="large-text" rows="10" name="%s[message_maintenance]">%s</textarea>', esc_attr(self::OPT_KEY), esc_textarea($s['message_maintenance']));
      echo '<p class="description">Cole aqui o HTML/texto como preferir. O plugin não impõe layout — você personaliza livremente.</p>';
      echo '</td></tr>';
    }

    echo '</table>';

    submit_button('Salvar');
    echo '</form>';

    echo '</div>';
  }

  public function admin_bar_notice($wp_admin_bar): void {
    if (!is_user_logged_in()) return;
    if (!current_user_can('manage_options')) return;

    $s = $this->get_settings();
    [$label, $color] = $this->current_mode_label($s);

    $dot = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . esc_attr($color) . ';margin-right:6px;vertical-align:middle"></span>';

    $wp_admin_bar->add_node([
      'id' => 'bcm-mm-root',
      'title' => $dot . 'Mode: ' . esc_html($label),
      'href' => '#',
      'meta' => ['title' => 'BCM Maintenance'],
    ]);

    // Dropdown options
    $wp_admin_bar->add_node([
      'id' => 'bcm-mm-online',
      'parent' => 'bcm-mm-root',
      'title' => 'Online',
      'href' => $this->adminbar_switch_url('online'),
    ]);

    $wp_admin_bar->add_node([
      'id' => 'bcm-mm-maintenance',
      'parent' => 'bcm-mm-root',
      'title' => 'Manutenção',
      'href' => $this->adminbar_switch_url('maintenance'),
    ]);

    $wp_admin_bar->add_node([
      'id' => 'bcm-mm-development',
      'parent' => 'bcm-mm-root',
      'title' => 'Desenvolvimento',
      'href' => $this->adminbar_switch_url('development'),
    ]);
  }

  private function is_allowed_request(): bool {
    // Always allow wp-admin and login endpoints.
    if (is_admin()) return true;

    // Allow REST and AJAX (keeps admin/editor tooling working).
    if (defined('DOING_AJAX') && DOING_AJAX) return true;
    if (defined('REST_REQUEST') && REST_REQUEST) return true;

    // Allow cron.
    if (defined('DOING_CRON') && DOING_CRON) return true;

    // Allow WP CLI.
    if (defined('WP_CLI') && WP_CLI) return true;

    // Allow login page to exist (but we do NOT link/redirect to it).
    $p = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($p, 'wp-login.php') !== false) return true;

    return false;
  }

  public function maybe_block_frontend(): void {
    $s = $this->get_settings();

    if (empty($s['enabled'])) return; // online

    if ($this->is_allowed_request()) return;

    // Allow admins to view everything.
    if (is_user_logged_in() && current_user_can('manage_options')) {
      return;
    }

    status_header(503);
    header('Retry-After: 3600');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $mode = $s['mode'] ?? 'maintenance';

    if ($mode === 'development') {
      $title = (string)$s['title_development'];
      $message = (string)$s['message_development'];
      $badge = 'DESENVOLVIMENTO';
    } else {
      $title = (string)$s['title_maintenance'];
      $message = (string)$s['message_maintenance'];
      $badge = 'MANUTENÇÃO';
    }

    // Keep layout minimal; user can paste HTML in message.
    $html = '<!doctype html><html lang="pt-BR"><head>' .
      '<meta charset="utf-8">' .
      '<meta name="viewport" content="width=device-width, initial-scale=1">' .
      '<meta name="robots" content="noindex, nofollow">' .
      '<title>' . esc_html($title) . '</title>' .
      '<style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;background:#0b1220;color:#e5e7eb;margin:0}
        .wrap{max-width:860px;margin:10vh auto;padding:24px}
        .card{background:#0f172a;border:1px solid #1f2937;border-radius:16px;padding:24px}
        .badge{display:inline-block;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#93c5fd;background:#0b2a4a;border:1px solid #1e3a8a;padding:6px 10px;border-radius:999px;margin-bottom:14px}
        h1{margin:0 0 10px;font-size:28px}
        .msg{color:#cbd5e1;line-height:1.6}
      </style>' .
      '</head><body><div class="wrap"><div class="card">' .
      '<div class="badge">' . esc_html($badge) . '</div>' .
      '<h1>' . esc_html($title) . '</h1>' .
      '<div class="msg">' . wp_kses_post($message) . '</div>' .
      '</div></div></body></html>';

    echo $html;
    exit;
  }
}

add_action('plugins_loaded', static function () {
  (new BCM_Maintenance_Mode())->hooks();
});
