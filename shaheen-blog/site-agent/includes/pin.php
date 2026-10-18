<?php
/**
 * Settings PIN: the merged plugin uses ONE PIN (SB_Lock). These shims keep the
 * old csa_pin_* calls working without a second PIN.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function csa_pin_unlocked() {
	return class_exists( 'SB_Lock' ) && SB_Lock::is_unlocked();
}

function csa_pin_notice() {}

function csa_pin_card() {}

function csa_pin_lock_screen() {
	$nonce = wp_create_nonce( 'sb_admin' );
	?>
	<div class="csa-card csa-pin-lock">
		<h2>🔒 <?php echo esc_html( csa_t( 'Settings are locked' ) ); ?></h2>
		<p><?php echo esc_html( csa_t( 'Enter the Settings PIN to open this page.' ) ); ?> (<?php echo esc_html( 'Hint: ' . SB_Lock::HINT ); ?>)</p>
		<p><input type="password" id="csa-sbpin" inputmode="numeric" maxlength="12" autocomplete="off" class="csa-pin-input">
		<button type="button" class="button button-primary" id="csa-sbpin-go"><?php echo esc_html( csa_t( 'Unlock' ) ); ?></button></p>
		<p id="csa-sbpin-msg" class="csa-err"></p>
	</div>
	<script>
	(function(){var b=document.getElementById('csa-sbpin-go'),i=document.getElementById('csa-sbpin'),m=document.getElementById('csa-sbpin-msg');
	function go(){var f=new FormData();f.append('action','sb_unlock');f.append('nonce','<?php echo esc_js( $nonce ); ?>');f.append('pin',i.value);
	fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',{method:'POST',credentials:'same-origin',body:f}).then(function(r){return r.json();}).then(function(j){
	if(j&&j.success){location.reload();}else{m.textContent=(j&&j.data&&j.data.message)||'PIN?';}}).catch(function(){m.textContent='Network error';});}
	b.addEventListener('click',go);i.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();go();}});})();
	</script>
	<?php
}
