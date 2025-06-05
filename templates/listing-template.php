<?php
/**
 * Template Name: Business Listing
 */

get_header();

// Get post meta data
$address = get_post_meta(get_the_ID(), '_business_address', true);
$phone = get_post_meta(get_the_ID(), '_business_phone', true);
$email = get_post_meta(get_the_ID(), '_business_email', true);
$website = get_post_meta(get_the_ID(), '_business_website', true);
$rating = get_post_meta(get_the_ID(), '_business_rating', true);
$gallery = get_post_meta(get_the_ID(), '_business_gallery', true);
$latitude = get_post_meta(get_the_ID(), '_business_latitude', true);
$longitude = get_post_meta(get_the_ID(), '_business_longitude', true);
?>

<div class="business-listing-container">
    <div class="business-header">
        <div class="container">
            <h1><?php the_title(); ?></h1>
            
            <?php if ($rating): ?>
            <div class="rating">
                <span class="stars"><?php echo str_repeat('★', $rating); ?></span>
                <span class="rating-value"><?php echo esc_html($rating); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($address): ?>
            <div class="address">
                <?php echo esc_html($address); ?>
            </div>
            <?php endif; ?>

            <div class="action-buttons">
                <button class="favorite-btn">
                    <i class="far fa-heart"></i> Favorite
                </button>
                <button class="share-btn">
                    <i class="fas fa-share"></i> Share
                </button>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row">
            <div class="col-md-8">
                <div class="business-content">
                    <section class="profile-section">
                        <h2>Profile</h2>
                        <?php the_content(); ?>
                    </section>

                    <?php if ($gallery): ?>
                    <section class="photos-section">
                        <h2>Photos</h2>
                        <div class="gallery">
                            <?php
                            $gallery_array = explode(',', $gallery);
                            foreach ($gallery_array as $image_id) {
                                echo wp_get_attachment_image($image_id, 'large', false, array('class' => 'gallery-image'));
                            }
                            ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if ($latitude && $longitude): ?>
                    <section class="map-section">
                        <h2>Map</h2>
                        <div id="business-map" style="height: 400px;"></div>
                    </section>
                    <?php endif; ?>

                    <section class="reviews-section">
                        <h2>Reviews</h2>
                        <?php comments_template(); ?>
                    </section>
                </div>
            </div>

            <div class="col-md-4">
                <div class="business-sidebar">
                    <div class="contact-info">
                        <?php if ($address): ?>
                        <div class="info-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <span><?php echo esc_html($address); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($phone): ?>
                        <div class="info-item">
                            <i class="fas fa-phone"></i>
                            <a href="tel:<?php echo esc_attr($phone); ?>"><?php echo esc_html($phone); ?></a>
                        </div>
                        <?php endif; ?>

                        <?php if ($email): ?>
                        <div class="info-item">
                            <i class="fas fa-envelope"></i>
                            <a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a>
                        </div>
                        <?php endif; ?>

                        <?php if ($website): ?>
                        <div class="info-item">
                            <i class="fas fa-globe"></i>
                            <a href="<?php echo esc_url($website); ?>" target="_blank">Visit Website</a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="business-hours">
                        <h3>Business Hours</h3>
                        <?php
                        $days = array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday');
                        foreach ($days as $day) {
                            $hours = get_post_meta(get_the_ID(), '_business_hours_' . strtolower($day), true);
                            if ($hours) {
                                echo '<div class="hours-row">';
                                echo '<span class="day">' . esc_html($day) . '</span>';
                                echo '<span class="hours">' . esc_html($hours) . '</span>';
                                echo '</div>';
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($latitude && $longitude): ?>
<script>
function initMap() {
    const map = new google.maps.Map(document.getElementById("business-map"), {
        zoom: 15,
        center: { lat: <?php echo $latitude; ?>, lng: <?php echo $longitude; ?> },
    });

    new google.maps.Marker({
        position: { lat: <?php echo $latitude; ?>, lng: <?php echo $longitude; ?> },
        map,
        title: "<?php echo esc_js(get_the_title()); ?>",
    });
}
</script>
<script async defer
    src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_API_KEY&callback=initMap">
</script>
<?php endif; ?>

<?php get_footer(); ?> 