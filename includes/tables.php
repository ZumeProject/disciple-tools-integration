<?php
/**
 * DT_Zume_Activity
 * @see https://wordpress.org/plugins/custom-list-table-example/
 */
if ( !class_exists( 'WP_List_Table' )){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

/**
 * Class DT_Zume_Activity
 */
class DT_Zume_Activity extends WP_List_Table {

    public static $token;
    /**
     * Call this public function to embed the list on a page: DT_Zume_Activity::forms_list_box
     */
    public static function list_box( $token = null ) {
        $list_table = new DT_Zume_Activity();
        $list_table->prepare_items( $token );

        ?>
        <div class="wrap">

            <!-- Forms are NOT created automatically, so you need to wrap the table in one to use features like bulk actions -->
            <form id="new-leads" method="get">
                <input type="hidden" name="page" value="<?php echo ( isset( $_REQUEST['page'] ) ) ? esc_attr( sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) ) : '' ?>" />
                <?php $list_table->display() ?>
            </form>

        </div>
        <?php
    }

    public function __construct(){
        $this->token = self::$token;

        //Set parent defaults
        parent::__construct( array(
            'singular'  => 'zume_activity',     //singular name of the listed records
            'plural'    => 'zume_activity',    //plural name of the listed records
            'ajax'      => true        //does this table support ajax?
        ) );
    }

    public function column_default( $item, $column_name){
        switch ($column_name){
            case 'name':
                return $item->post_title;

            case 'data':
                // list form contents
                $meta = dt_get_simple_post_meta( $item->ID );
                foreach ( $meta as $key => $value ) {
                    if ( 'name' == $key || 'email' == $key || 'phone' == $key ) {
                        print esc_attr( $key ) . ': ' . esc_attr( $value ) . '<br>';
                    }
                }
                return '';

            case 'source':
                $token = get_post_meta( $item->ID, 'token', true );
                $args = array(
                'meta_key' => 'token',
                'meta_value' => $token,
                'post_type' => 'dt_zume_forms',
                );
                $source = new WP_Query( $args );
                if ( $source->found_posts < 1 ) {
                    return __( 'No longer available', 'dt_zume' );
                }
                print '<a href="' . esc_attr( admin_url() ) .'post.php?post='. esc_attr( $source->posts[0]->ID ).'&action=edit">' . esc_attr( $source->posts[0]->post_title ) . '</a>';
                return '';

            case 'date':
                return $item->post_date;
            case 'transfer':
                return ( get_post_meta( $item->ID, 'scheduled_for_transfer', true ) ) ? 'Yes' : '';

            default:
                return print_r( $item, true ); //Show the whole array for troubleshooting purposes
        }
    }


    public function column_cb( $item){
        return sprintf(
            '<input type="checkbox" name="%1$s[]" value="%2$s" />',
            /*$1%s*/ $this->_args['singular'],  //Let's simply repurpose the table's singular label
            /*$2%s*/ $item->ID                //The value of the checkbox should be the record's id
        );
    }

    public function get_columns(){
        $columns = array(
        'cb'        => '<input type="checkbox" />', //Render a checkbox instead of text
        'name'      => 'Name',
        'data'      => 'Form Data',
        'source'    => 'Source',
        'date'      => 'Date Submitted',
        'transfer' => 'Transfer Scheduled'
        );
        return $columns;
    }

    public function get_sortable_columns() {
        $sortable_columns = array(
        'name'     => array( 'name', false ),     //true means it's already sorted
        );
        return $sortable_columns;
    }

    public function get_bulk_actions() {
        $actions = [
        'delete'    => __( 'Delete', 'dt_zume' ),
        ];

        $state = get_option( 'dt_zume_state' );
        switch ( $state ) {
            case 'home':
            case 'combined':
                $actions['approve'] = __( 'Approve', 'dt_zume' ); // for the home drop down to approve a stuck record
                break;
            default: // if no option exists, then the plugin is forced to selection screen.
                $actions['transfer'] = __( 'Transfer', 'dt_zume' ); // for the remote drop down to transfer a stuck record
                break;
        }

        return $actions;
    }

    public function process_bulk_action() {

        //Detect when a bulk action is being triggered...
        if ( 'delete' === $this->current_action() ) {

            if ( ! isset( $_GET['form'] ) ) {
                return new WP_Error( 'failed_to_delete', 'Form field missing' );
            }
            $selected_records = array_map( 'sanitize_key', wp_unslash( $_GET['form'] ) );

            foreach ( $selected_records as $selected_record ) {
                $result = wp_delete_post( $selected_record, true );

                if ( is_wp_error( $result ) || ! $result ) {
                    return new WP_Error( 'failed_to_delete', 'Failed to delete: ' . $selected_record );
                }
            }
        }

        if ( 'approve' === $this->current_action() ) {

            if ( ! isset( $_GET['form'] ) || ! is_array( $_GET['form'] ) ) {
                return new WP_Error( 'failed_bulk_actions', 'Form data and/or array missing' );
            }

            $selected_records = array_map( 'sanitize_key', wp_unslash( $_GET['form'] ) );

            dt_write_log( $selected_records );

            foreach ( $selected_records as $selected_record ) {

                $result = DT_Webform_Home::create_contact_record( $selected_record );

                if ( is_wp_error( $result ) ) {
                    dt_write_log( 'process_bulk_action()' );
                    dt_write_log( 'failed to create contact ' . $selected_record . ' (' . $result->get_error_message() . ')' ); // @todo do something with a failed record approval
                }
            }
        }

        if ( 'transfer' === $this->current_action() ) {
            if ( isset( $_GET['form'] ) ) {
                $selected_records = array_map( 'sanitize_key', wp_unslash( $_GET['form'] ) );
                DT_Webform_Remote::trigger_transfer_of_new_leads( $selected_records );
            }
        }
    }

    public function prepare_items( $token = null ) {

        $per_page = 10;
        $order = ( !empty( $_REQUEST['order'] ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'asc'; //If no order, default to asc
        $paged = ( !empty( $_REQUEST['paged'] ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['paged'] ) ) : '1'; //If no order, default to asc

        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array( $columns, $hidden, $sortable );
        $this->process_bulk_action();

        $args = [
        'post_type' => 'dt_zume_new_leads',
        'posts_per_page' => $per_page,
        'order' => $order,
        'paged' => $paged,
        ];

        // Check for specific list
        if ( ! is_null( $token ) ) {
            $args['meta_value'] = $token;
        }

        $data = new WP_Query( $args );

        $total_items = $data->found_posts;
        $this->items = $data->posts;

        $this->set_pagination_args( array(
            'total_items' => $total_items,                  //WE have to calculate the total number of items
            'per_page'    => $per_page,                     //WE have to determine how many items to show on a page
            'total_pages' => ceil( $total_items /$per_page )   //WE have to calculate the total number of pages
        ) );
    }


}