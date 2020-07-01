<?php
/**
 * This class serves all aspects of the Feefo integration with the 
 * product schema served via Google Tag Manager.
 * 
 * @author Jackson Lewis
 */
class Product_Schema_Feefo_Integration {

    /** @var string The merchant identifier */
    var $merchant_identifier = '';

    /** @var string Feefo API base url */
    var $feefo_api_base_url = 'https://api.feefo.com/api/';

    /** @var string Feefo API version */
    var $feefo_api_version = '10';

    /** @var string The cron hook */
    var $cron_hook = 'product_schema_feefo_data_refresh';

    /** @var string The WP option key prefix */
    var $option_prefix = 'feefo_data__';


    /**
     * Init
     */
    function init() {
        $this->define_cron_event();

        add_action( 'wp_head_open', array( $this, 'GTM_dataLayer' ) );
    }

    /**
     * Schedule the cron event
     */
    function define_cron_event() {
        if ( ! wp_next_scheduled( $this->cron_hook ) ) {
            wp_schedule_event( time(), 'daily', $this->cron_hook );
        }
        
        add_action( $this->cron_hook, array( $this, 'get_summary_data' ) );
        add_action( $this->cron_hook, array( $this, 'get_reviews_data' ) );
    }

    /**
     * Get Feefo option
     * 
     * @param string $option_suffix The option
     * @return string The returned option data
     */
    function get_option_name( $option_suffix = '' ) {
        return $this->option_prefix . $option_suffix;
    }

    /**
     * String together the main portion of the Feefo API url
     * 
     * @return string The base url including the api version
     */
    function feefo_api_url() {
        return $this->feefo_api_base_url . $this->feefo_api_version;
    }

    /**
     * Call the Feefo API and return the response
     * 
     * @param string The Feefo api url
     * @param array Any options to pass to wp_remote_request()
     * @return object The response
     */
    function get_feefo_response( $url = '', $options = array() ) {
        $response = wp_remote_get( $url, $options );

        return json_decode( $wp_response['body'] );
    }

    /**
     * Call the /reviews/summary/product endpoint
     */
    function get_summary_data() {

        $url = $this->feefo_api_url() . '/reviews/summary/product';
        $response = $this->get_feefo_response( $url );

        $summary_product_rating = $response->rating->rating;
        $summary_product_rating_count = $response->rating->service->count;

        update_option( $this->get_option_name( 'summary_product_rating' ), $summary_product_rating );
        update_option( $this->get_option_name( 'summary_product_rating_count' ), $summary_product_rating_count );
    }

    /**
     * Call the /reviews/product endpoint
     */
    function get_reviews_data() {
        
        $url = $this->feefo_api_url() . '/reviews/product';
        $response = $this->get_feefo_response( $url );

        $reviews = array();

        if ( ! isset( $response->reviews ) ) {
            foreach ( $response->reviews as $review ) {

                $_review = array();
                $review_object = $review->products[0];
        
                $reviewer = $review->customer->display_name ?: 'anonymous';
                $review_date = substr( $review_object->created_at, 0, -14 );
                $review_score = $review_object->rating->rating;
                $review_content = $review_object->review;
                
                // Here we construct the required format of the Schema
                $_review['@type'] = 'Review';
                $_review['author'] = $reviewer;
                $_review['datePublished'] = $review_date;
                $_review['description'] = $review_content;
                $_review['reviewRating'] = (object) array(
                    '@type' => 'Rating',
                    'bestRating' => '5',
                    'ratingValue' => $review_score,
                    'worstRating' => '1'
                );
        
                // Add to main array
                $reviews[] = $_review;
            }
        
            update_option( $this->get_option_name( 'reviews' ), $reviews );
        }
    }

    /**
     * Output the script to the <head>
     * 
     * This must be output before the main GTM tag in the head, otherwise the schema 
     * will not generate properly at load time.
     */
    function GTM_dataLayer() {
        $summary_product_rating_score = get_option( $this->get_option_name( 'summary_product_rating' ) );
        $summary_product_rating_count = get_option( $this->get_option_name( 'summary_product_rating_count' ) );
        $product_rating_reviews = get_option( $this->get_option_name( 'reviews' ) );

        ?>
<script async>
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
        'RatingScore': <?php echo $summary_product_rating_score; ?>,
        'RatingCount': <?php echo $summary_product_rating_count; ?>,
        'Reviews': <?php echo wp_json_encode( $product_rating_reviews ); ?>
    });
</script>
        <?php
    }
}


/**
 * Init our Product_Schema_Feefo_Integration
 */
function product_schema_feefo_integration() {

    $instance = new Product_Schema_Feefo_Integration;
    $instance->init();
}
product_schema_feefo_integration();
