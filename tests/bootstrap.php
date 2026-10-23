<?php
/**
 * Unit-test bootstrap. These tests cover the WordPress-free classes (Policy, Settings sanitization),
 * so no WordPress install is needed. The integration path is `wp lean-uploads self-test` on a real site.
 */

namespace LeanUploads;

\define( 'ABSPATH', __DIR__ . '/' );

const OPTION = 'lean_uploads';

require_once dirname( __DIR__ ) . '/includes/class-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-rewriter.php';
