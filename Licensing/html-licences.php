<?php
/**
 * File containing the view to show the table for managing license keys.
 *
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h1 class=""><?php esc_html_e( 'HonorsWP Licenses' ); ?></h1>
	<h2>All Access License</h2>
	<p>Will activate automatic updates for HonorsWP plugins.</p>
		<div class="honorswp-licences">
			<div class="licence-row">
				<div class="plugin-info">All Access</div>
				<div class="plugin-licence">
					<?php
					$notices = $this->get_messages( 'all-access' );
					if ( ! is_array( $notices ) ) {
						echo '<div class="notice inline notice-success honors-license-notice"><p>' . wp_kses_post( $notices ) . '</p></div>';
					} else {
						foreach ( $notices as $message ) {
							echo '<div class="notice inline notice-error honors-license-notice"><p>' . wp_kses_post( $message ) . '</p></div>';
						}
					}
					?>
					<form method="post">
					<?php
					$licence = EA\Licensing\License::get_plugin_licence_static( 'all-access' );

					wp_nonce_field( 'honorswp-manage-licence' );

					if ( ! empty( $licence['licence_key'] ) ) {
						?>
						<input type="hidden" id="all-access_action" name="action" value="deactivate"/>
						<input type="hidden" id="all-access_plugin" name="product_slug" value="all-access"/>

						<label for="all-access_licence_key">
							<input type="text" class="ea-license-text" disabled="disabled" id="all-access_licence_key" name="licence_key" placeholder="XXXX-XXXX-XXXX-XXXX" value="<?php echo esc_attr( $licence['licence_key'] ); ?>"/>
						</label>
						<div class="ea-button-row">
						<span class="btn ea-submit-button">
							<input type="submit" class="button deactivate" name="submit" value="<?php esc_attr_e( 'Deactivate License' ); ?>" />
						</span>
						<span class="btn">
							<input type="submit" class="button check" name="submit" value="<?php esc_attr_e( 'Check License' ); ?>" />
						</span>
						</div>
						<?php
					} else { // licence is not active.
						?>
						<input type="hidden" id="all-access_action" name="action" value="activate"/>
						<input type="hidden" id="all-access_plugin" name="product_slug" value="all-access"/>
						<label for="all-access_licence_key">
							<input type="text" class="ea-license-text" id="all-access_licence_key" name="licence_key" placeholder="XXXX-XXXX-XXXX-XXXX" required/>
						</label>

						<div class="btn ea-submit-button">
							<input type="submit" class="button activate" name="submit" value="<?php esc_attr_e( 'Activate License' ); ?>" />
						</div>

						<div class="">

						</div>
						<?php
					} // end if : else licence is not active.
					?>
					</form>
				</div>
			</div>
		</div>
	<h2>Single Plugin Licenses</h2>
	<?php
	$all_access_active = get_option( 'honors_license_key', true );
	if ( ! empty( $all_access_active['all-access'] ) &&
	! empty( $all_access_active['all-access']['licence_key'] ) &&
	! empty( $all_access_active['all-access']['status'] &&
	'valid' === $all_access_active['all-access']['status'] ) ) {
		?>
		<p>No need to enter individial license keys because your All Access pass is active.</p>
		<?php
	} else { ?>
	<p>Activates automatic updates for individual HonorsWP plugins.</p>
	<p>Don't use if you are using an All Access Pass license.</p>
		<div class="honorswp-licences">
		<?php if ( ! empty( $licenced_plugins ) ) :
				foreach ( $licenced_plugins as $product_slug => $plugin_data ) :

					// error_log(print_r(['html licenses product_slug', $product_slug], true));
					// error_log(print_r(['plugin data', $plugin_data], true));
					$licence = EA\Licensing\License::get_plugin_licence_static( $product_slug );
					// error_log(print_r(['licence', $licence], true));
					?>
					<div class="licence-row">
						<div class="plugin-info">
							<?php echo esc_html( $plugin_data['Name'] ); ?>
						</div>
						<div class="plugin-licence">
							<?php
							// $notices = EA\Licensing\License::get_messages_static( $product_slug );
							$notices = $this->get_messages( $product_slug );
							// error_log(print_r( $notices, true ) );
							foreach ( $notices as $message ) {
								echo '<div class="notice inline notice-error honors-license-notice"><p>' . wp_kses_post( $message ) . '</p></div>';
							}
							?>
							<form method="post">
								<?php

								wp_nonce_field( 'honorswp-manage-licence' );

								if ( ! empty( $licence['licence_key'] ) ) {
									?>
									<input type="hidden" id="<?php echo esc_attr( sanitize_title( $product_slug ) ); ?>_action" name="action" value="deactivate"/>
									<input type="hidden" id="<?php echo esc_attr( sanitize_title( $product_slug ) ); ?>_plugin" name="product_slug" value="<?php echo esc_attr( $product_slug ); ?>"/>

									<label for="<?php echo esc_attr( sanitize_title( $product_slug ) ); ?>_licence_key">
										<input type="text" class="ea-license-text" disabled="disabled" id="<?php echo esc_attr( sanitize_title( $product_slug ) ); ?>_licence_key" name="licence_key" placeholder="XXXX-XXXX-XXXX-XXXX" value="<?php echo esc_attr( $licence['licence_key'] ); ?>"/>
									</label>

									<div class="btn ea-submit-button">
										<input type="submit" class="button deactivate" name="submit" value="<?php esc_attr_e( 'Deactivate License' ); ?>" />
									</div>
									<div class="btn">
										<input type="submit" class="button check" name="submit" value="<?php esc_attr_e( 'Check License' ); ?>" />
									</div>
									<?php
								} else { // licence is not active.
									?>
									<input type="hidden" id="<?php echo esc_attr( sanitize_title( $product_slug ) ); ?>_action" name="action" value="activate"/>
									<input type="hidden" id="<?php echo esc_attr( sanitize_title( $product_slug ) ); ?>_plugin" name="product_slug" value="<?php echo esc_attr( $product_slug ); ?>"/>
									<label for="<?php echo esc_attr( sanitize_title( $product_slug ) ); ?>_licence_key">
										<input type="text" class="ea-license-text" id="<?php echo esc_attr( sanitize_title( $product_slug ) ); ?>_licence_key" name="licence_key" placeholder="XXXX-XXXX-XXXX-XXXX" required/>
									</label>

									<div class="btn ea-submit-button">
										<input type="submit" class="button activate" name="submit" value="<?php esc_attr_e( 'Activate License' ); ?>" />
									</div>

									<div class="">

									</div>
									<?php
								} // end if : else licence is not active.
								?>
							</form>
						</div>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No plugins are activated that have licenses managed by HonorsWP.' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
<?php }
