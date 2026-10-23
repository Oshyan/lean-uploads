<?php
/**
 * Tools > Lean Uploads: scan the library and convert it in batches from the browser.
 *
 * @package LeanUploads
 */

namespace LeanUploads;

defined( 'ABSPATH' ) || exit;

final class Admin_Tools {

	const SLUG = 'lean-uploads';

	/** @var Bulk */
	private $bulk;

	public function __construct( Bulk $bulk ) {
		$this->bulk = $bulk;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	public function menu(): void {
		add_management_page( __( 'Lean Uploads', 'lean-uploads' ), __( 'Lean Uploads', 'lean-uploads' ), Bulk::CAP, self::SLUG, array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( Bulk::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'lean-uploads' ) );
		}
		$state = $this->bulk->state();
		$nonce = wp_create_nonce( Bulk::AJAX );
		?>
		<div class="wrap" id="lean-uploads-tools">
			<h1><?php esc_html_e( 'Lean Uploads', 'lean-uploads' ); ?></h1>
			<p><?php esc_html_e( 'Convert the images already in the Media Library to the configured output format and size cap, exactly as new uploads are. Each image is processed on its own; if anything goes wrong with one, it is left as it was and reported below.', 'lean-uploads' ); ?></p>
			<p class="description"><?php esc_html_e( 'This replaces original files. Have a backup. Keep this tab open while it runs; closing it pauses the run and you can resume later.', 'lean-uploads' ); ?></p>

			<h2><?php esc_html_e( 'Library', 'lean-uploads' ); ?></h2>
			<p><button type="button" class="button" id="lu-scan"><?php esc_html_e( 'Scan library', 'lean-uploads' ); ?></button> <span id="lu-scan-status" class="description"></span></p>
			<table class="widefat striped" id="lu-summary" style="max-width:640px;display:none">
				<thead><tr><th><?php esc_html_e( 'Would', 'lean-uploads' ); ?></th><th><?php esc_html_e( 'Images', 'lean-uploads' ); ?></th><th><?php esc_html_e( 'Current size', 'lean-uploads' ); ?></th></tr></thead>
				<tbody></tbody>
			</table>

			<h2><?php esc_html_e( 'Convert', 'lean-uploads' ); ?></h2>
			<p>
				<label><input type="checkbox" id="lu-rewrite" checked> <?php esc_html_e( 'Rewrite old image URLs in posts, meta and options to the new files', 'lean-uploads' ); ?></label><br>
				<label><input type="checkbox" id="lu-dry"> <?php esc_html_e( 'Dry run (report only, change nothing)', 'lean-uploads' ); ?></label>
			</p>
			<p>
				<button type="button" class="button button-primary" id="lu-start"><?php esc_html_e( 'Start', 'lean-uploads' ); ?></button>
				<button type="button" class="button" id="lu-pause"><?php esc_html_e( 'Pause', 'lean-uploads' ); ?></button>
				<button type="button" class="button" id="lu-resume"><?php esc_html_e( 'Resume', 'lean-uploads' ); ?></button>
				<button type="button" class="button" id="lu-reset"><?php esc_html_e( 'Reset', 'lean-uploads' ); ?></button>
			</p>
			<div id="lu-progress" style="max-width:640px;background:#dcdcde;height:18px;border-radius:3px;overflow:hidden"><div id="lu-bar" style="background:#2271b1;height:100%;width:0"></div></div>
			<p id="lu-counts" class="description"></p>
			<pre id="lu-log" style="max-width:640px;max-height:300px;overflow:auto;background:#fff;border:1px solid #dcdcde;padding:8px;font-size:12px"></pre>
		</div>
		<script>
		(function () {
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, nonce = <?php echo wp_json_encode( $nonce ); ?>, action = <?php echo wp_json_encode( Bulk::AJAX ); ?>;
			var initial = <?php echo wp_json_encode( array_diff_key( $state, array( 'ids' => 1 ) ) ); ?>;
			var running = false, $ = function (id) { return document.getElementById(id); };
			function fmt(b) { var u = ['B','KB','MB','GB'], i = 0; while (b >= 1024 && i < 3) { b /= 1024; i++; } return (i ? b.toFixed(1) : b) + ' ' + u[i]; }
			function call(op, extra) {
				var body = new URLSearchParams(Object.assign({ action: action, nonce: nonce, op: op }, extra || {}));
				return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); }).then(function (j) { if (!j.success) { throw new Error(j.data && j.data.message ? j.data.message : 'request failed'); } return j.data; });
			}
			function show(state) {
				var pct = state.total ? Math.round(100 * state.cursor / state.total) : 0;
				$('lu-bar').style.width = pct + '%';
				$('lu-counts').textContent = state.status + ': ' + state.cursor + ' / ' + state.total + ' processed, ' + state.done + ' converted, ' + state.skipped + ' skipped, ' + state.failed + ' failed, ' + fmt(state.saved) + ' saved' + (state.dry ? ' (dry run)' : '');
				$('lu-log').textContent = (state.log || []).join('\n');
				$('lu-log').scrollTop = $('lu-log').scrollHeight;
				$('lu-start').disabled = state.status === 'running';
				$('lu-pause').disabled = state.status !== 'running';
				$('lu-resume').disabled = state.status !== 'paused';
			}
			function loop() {
				if (!running) { return; }
				call('step').then(function (d) { show(d.state); if (d.state.status === 'running') { loop(); } else { running = false; } }).catch(function (e) { running = false; $('lu-counts').textContent = 'Stopped: ' + e.message + ' (click Resume to continue)'; });
			}
			$('lu-scan').onclick = function () {
				$('lu-scan-status').textContent = 'scanning…';
				call('summary').then(function (d) {
					var tb = $('lu-summary').querySelector('tbody'); tb.innerHTML = '';
					var labels = { convert: 'be converted', resize: 'be resized to the cap', skip: 'be left alone', missing: 'be skipped (file missing)' };
					Object.keys(d.summary).forEach(function (k) {
						var tr = document.createElement('tr');
						[labels[k] || k, d.summary[k].count, fmt(d.summary[k].bytes)].forEach(function (t) { var td = document.createElement('td'); td.textContent = t; tr.appendChild(td); });
						tb.appendChild(tr);
					});
					$('lu-summary').style.display = ''; $('lu-scan-status').textContent = '';
				}).catch(function (e) { $('lu-scan-status').textContent = e.message; });
			};
			$('lu-start').onclick = function () {
				if (!$('lu-dry').checked && !window.confirm(<?php echo wp_json_encode( __( 'This will replace original image files in the Media Library. Continue?', 'lean-uploads' ) ); ?>)) { return; }
				call('start', { rewrite: $('lu-rewrite').checked ? 1 : 0, dry: $('lu-dry').checked ? 1 : 0 }).then(function (d) { show(d.state); running = d.state.status === 'running'; loop(); });
			};
			$('lu-pause').onclick = function () { running = false; call('pause').then(function (d) { show(d.state); }); };
			$('lu-resume').onclick = function () { call('resume').then(function (d) { show(d.state); running = d.state.status === 'running'; loop(); }); };
			$('lu-reset').onclick = function () { running = false; call('reset').then(function (d) { show(d.state); }); };
			show(initial);
		})();
		</script>
		<?php
	}
}
