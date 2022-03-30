<?php
/**
 * File containing the view to show the table for managing license keys.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h1 class="screen-reader-text"><?php esc_html_e( 'HonorsWP Licenses' ); ?></h1>
<div class="honorswp-licences">
<?php if ( ! empty( $licenced_plugins ) ) :
		foreach ( $licenced_plugins as $product_slug => $plugin_data ) :

			$licence = EA_Style\Licensing\License::get_plugin_licence( $product_slug );
			?>
			<div class="licence-row">
				<div class="plugin-info">
					<?php echo esc_html( $plugin_data['Name'] ); ?>
				</div>
				<div class="plugin-licence">
					<?php
					$notices = EA_Style\Licensing\License::get_messages( $product_slug );
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
							<?php
						} else { // licence is not active.
							?>
							<input type="hidden" id="<?php echo esc_attr( sanitize_title( $product_slug ) ); ?>_action" name="action" value="activate"/>
							<input type="hidden" id="<?php echo esc_attr( sanitize_title( $product_slug ) ); ?>_plugin" name="product_slug" value="<?php echo esc_attr( $product_slug ); ?>"/>
							<label for="<?php echo esc_attr( sanitize_title( $product_slug ) ); ?>_licence_key">
								<input type="text" class="ea-license-text" id="<?php echo esc_attr( sanitize_title( $product_slug ) ); ?>_licence_key" name="licence_key" placeholder="XXXX-XXXX-XXXX-XXXX"/>
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
