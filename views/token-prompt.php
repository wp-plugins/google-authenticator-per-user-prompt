<?php
	if ( function_exists( 'login_header' ) ) {
		login_header();
	}
?>

<?php if ( $error_message ) : ?>
	<div id="login_error">
		<?php echo wp_kses( $error_message, array( 'strong' => array() ) ); ?>
	</div>
<?php endif; ?>

<form action="<?php echo esc_url( $action_url ); ?>" method="post" autocomplete="off">
	<input type="hidden" name="user_id"           value="<?php echo absint( $user->ID ); ?>" />
	<input type="hidden" name="gapup_login_nonce" value="<?php echo esc_attr( $_REQUEST['gapup_login_nonce'] ) ?>" />
	<input type="hidden" name="redirect_to"       value="<?php echo esc_attr( $redirect_to ) ?>" />

	<?php GoogleAuthenticator::$instance->loginform(); ?>

	<p class="submit">
		<input type="submit" id="gapup_token_prompt" name="gapup_token_prompt" class="button button-primary button-large" value="<?php esc_attr_e( 'Log In' ); ?>" />
	</p>
</form>
	
<?php
	if ( function_exists( 'login_footer' ) ) {
		login_footer( 'user_email' );    // This actually focuses the 2FA token field, but Google Authenticator named it user_email
	}
?>
