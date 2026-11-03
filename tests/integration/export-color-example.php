<?php
require_once ABSPATH . 'wp-admin/includes/image.php';
_wp_image_editor_choose();
$dir = isset( $args[0] ) ? $args[0] : sys_get_temp_dir() . '/lean-color-example-' . bin2hex( random_bytes( 8 ) );
if ( file_exists( $dir ) ) { WP_CLI::error( 'Output fixture directory already exists.' ); }
mkdir( $dir, 0700 );
$icc = file_get_contents( ABSPATH . 'wp-content/plugins/lean-uploads/tests/fixtures/synthetic-gamma18.icc' );
$im = new Imagick(); $im->newImage( 800, 600, 'transparent', 'PNG' );
$d = new ImagickDraw();
foreach ( array( array('rgb(180,65,42)',0,0,399,299),array('rgb(60,150,200)',400,0,799,299),array('rgb(120,120,120)',0,300,399,599),array('rgba(60,160,80,0.5)',400,300,799,599) ) as $patch ) {
 $d->setFillColor( $patch[0] ); $d->rectangle( $patch[1],$patch[2],$patch[3],$patch[4] );
}
$im->drawImage( $d ); $im->profileImage( 'icc', $icc ); $im->writeImage( $dir . '/source.png' ); $im->clear();
$c = LeanUploads\Plugin::instance()->converter; $rows = array();
foreach ( array( 'preserve.png'=>'image/png', 'preserve.webp'=>'image/webp', 'preserve.avif'=>'image/avif' ) as $name=>$mime ) {
 if ( ! LeanUploads\Plugin::instance()->capabilities->supports( $mime ) ) { WP_CLI::log( 'SKIP unavailable encoder: '.$mime ); continue; }
 $r = $c->convert( $dir.'/source.png', $dir.'/'.$name, $mime, array('quality'=>85,'max_dimension'=>400,'color_mode'=>'preserve','lossless'=> 'image/webp' === $mime) );
 if ( is_wp_error( $r ) ) { WP_CLI::error( $name.': '.$r->get_error_message() ); }
 $rows[$name]=$r;
}
$r = LeanUploads\Color_Profiles::normalize_file( $dir.'/source.png',$dir.'/normalized.png' );
if ( is_wp_error( $r ) ) { WP_CLI::error( $r->get_error_message() ); }
$token = LeanUploads\Color_Profiles::push_context( $dir.'/source.png', 'preserve' );
try {
 $protected = wp_get_image_editor( $dir.'/source.png' );
 if ( is_wp_error( $protected ) ) { WP_CLI::error( $protected->get_error_message() ); }
 $protected->resize(400,300);
 $saved = $protected->save($dir.'/protected-thumbnail.png','image/png');
 if ( is_wp_error( $saved ) ) { WP_CLI::error( $saved->get_error_message() ); }
} finally { LeanUploads\Color_Profiles::pop_context( $token ); }
$native = new WP_Image_Editor_Imagick( $dir.'/source.png' ); $native->load(); $native->resize(400,300); $native->save($dir.'/native-imagick.png','image/png');
$native = new WP_Image_Editor_GD( $dir.'/source.png' ); $native->load(); $native->resize(400,300); $native->save($dir.'/native-gd.png','image/png');
foreach ( glob($dir.'/*') as $file ) {
 $info=LeanUploads\Color_Profiles::inspect($file); unset($info['icc']);
 $rows[basename($file)]=array('bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file),'profile'=>$info);
}
// Time the shipped metadata parser/validation, rather than an alternative image-library API.
$timings=array();
for($i=0;$i<100;$i++) { $t=microtime(true); $r=LeanUploads\Color_Profiles::validate_file($dir.'/preserve.webp',$icc); $timings[]=(microtime(true)-$t)*1000; }
sort($timings); $rows['validation_median_ms']=$timings[50];
file_put_contents($dir.'/manifest.json',json_encode($rows,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
WP_CLI::success('Synthetic saved outputs exported to '.$dir.'; profile validation median '.round($timings[50],3).'ms.');
