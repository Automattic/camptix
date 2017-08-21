<?php

defined( 'WPINC' ) or die();

/**
 * @var array $sizes_by_site
 */

if ( empty ( $sizes_by_site ) ) {
	esc_html_e( 'Unable to find any t-shirt size data.', 'camptix' );

	return;
}

?>

<?php foreach ( $sizes_by_site as $site_id => $site ) : ?>
	<h3>
		<?php echo esc_html( $site['name'] ); ?>
	</h3>

	<?php if ( ! empty( $site['message'] ) ) : ?>
		<div>
			<?php echo wp_kses( $site['message'], 'post' ); ?>
		</div>
	<?php endif; ?>

	<table>
		<thead>
			<tr>
				<th><?php esc_html_e( 'Size',       'camptix' ); ?></th>
				<th><?php esc_html_e( 'Count',      'camptix' ); ?></th>
				<th><?php esc_html_e( 'Percentage', 'camptix' ); ?></th>
			</tr>
		</thead>

		<tbody>
			<?php foreach ( $site['sizes'] as $size => $size_count ) : ?>
				<?php $percentage = round( $size_count / array_sum( $site['sizes'] ) * 100 ); ?>

				<tr>
					<td><?php echo esc_html( $size       ); ?> </td>
					<td><?php echo esc_html( $size_count ); ?> </td>
					<td><?php echo esc_html( $percentage ); ?>%</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endforeach; ?>
