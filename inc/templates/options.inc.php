<?php
//adding namespace for text domain
use Tanios\ContentfulWp\Plugin;

?>
<div class="wrap">
	
	<div class="card" style="max-width: 50%;">
		
		<h1><?= __( 'Contentful', Plugin::TEXTDOMAIN ); ?></h1>

		<?php if ( !empty( $messages ) ): ?>

			<?php foreach ( $messages as $message => $success ): ?>
				<div class="<?php if( ! $success ): ?>error settings-error<?php else: ?>updated settings-updated<?php endif; ?> notice is-dismissible">
					<p>
						<strong><?= $message; ?></strong>
					</p>
					<button type="button" class="notice-dismiss">
						<span class="screen-reader-text">Dismiss this notice.</span>
					</button>
				</div>
			<?php endforeach; ?>

		<?php endif; ?>
		
		<form method="POST">

			<?php if( ! $client_id ): ?>

				<h3><?= __( 'Create an OAuth2 app', Plugin::TEXTDOMAIN ); ?></h3>

				<p><?= __( "For this plugin to work, you need to create an Oauth2 app for your Contentful account.", Plugin::TEXTDOMAIN ); ?></p>

				<p>
					<?php
					printf(
						__( "Use %s to create an OAuth app. For the redirection URI, you must use the one below:", Plugin::TEXTDOMAIN ),
						sprintf( '<a href="%s" target="_blank">%s</a>', 'https://be.contentful.com/account/profile/developers/applications/new', __( 'this link' ) )
					);
					?>
				</p>

				<p>
					<code><?php echo $redirect_url; ?></code>
				</p>

				<br><br>

				<h3><?= __( 'Connect to the app', Plugin::TEXTDOMAIN ); ?></h3>

				<table class="form-table">

					<tbody>

					<tr>
						<th scope="row">
							<label for="client_id"><?php _e( 'Client ID', Plugin::TEXTDOMAIN ); ?></label>
						</th>
						<td>
							<input type="text" name="client_id" value="<?php echo $client_id; ?>" id="client_id">
						</td>
					</tr>

					</tbody>

				</table>

				<p>
					<?php wp_nonce_field( 'contentful_connect', 'contentful_connect_nonce' ); ?>
					<input type="submit" name="submit" class="button button-primary"
					       value="<?= __( 'Save', Plugin::TEXTDOMAIN ); ?>">
				</p>

			<?php else: ?>

				<?php if( ! $imported ): ?>

					<h3><?php _e( 'Import your Contentful to WordPress', Plugin::TEXTDOMAIN ); ?></h3>

					<p><?php _e( "Now that you are connected to your Contentful application, you can import your content to WordPress right away using this page.", Plugin::TEXTDOMAIN ); ?></p>

					<?php if( ! $space ): ?>
						<table class="form-table">

							<tbody>

							<tr>
								<th scope="row">
									<label for="space"><?php _e( 'Select a space to import from', Plugin::TEXTDOMAIN ); ?></label>
								</th>
								<td>
									<select name="space" id="space">
										<?php foreach( $spaces->items as $s ): ?>
											<option value="<?php echo $s->sys->id; ?>"><?php echo $s->name; ?></option>
										<?php endforeach; ?>
									</select>
									<!-- /#space -->
								</td>
							</tr>

							</tbody>

						</table>

						<?php wp_nonce_field( 'contentful_select_space', 'contentful_select_space_nonce' ); ?>
						<input type="submit" class="button button-primary" value="<?php _e( "Select space", Plugin::TEXTDOMAIN ); ?>">

					<?php else: ?>

						<?php if( $content_types ): ?>

							<table class="form-table">

								<tbody>

								<?php foreach( $content_types->items as $content_type ): ?>

									<tr>
										<th scope="row">
											<label for="content_type_<?php echo $content_type->sys->id; ?>"><?php echo $content_type->name; ?></label>
										</th>
										<td>
											<input type="checkbox" name="content_types[]" value="<?php echo $content_type->sys->id; ?>" id="content_type_<?php echo $content_type->sys->id; ?>">
										</td>
									</tr>

								<?php endforeach; ?>

								</tbody>

							</table>

							<?php wp_nonce_field( 'contentful_import_content_types', 'contentful_import_content_types_nonce' ); ?>
							<input type="submit" class="button button-primary" value="<?php _e( "Import", Plugin::TEXTDOMAIN ); ?>">

						<?php endif; ?>

					<?php endif; ?>

				<?php endif; ?>

			<?php endif; ?>

		</form>
	
	</div>

</div>