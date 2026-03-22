<?php

declare(strict_types=1);

namespace Trumpet\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use DateTime;
use Exception;
use WP_Post;
use WP_Query;
use Trumpet\Announcement\AnnouncementManager;
use Trumpet\Announcement\AnnouncementRepositoryInterface;
use Trumpet\Config\TrumpetConfig;

/**
 * Class TrumpetAdmin
 *
 * Handles all admin-related functionality for announcements
 */
class TrumpetAdmin
{
    private AnnouncementManager $manager;
    private AnnouncementRepositoryInterface $repository;

    /**
     * Constructor
     *
     * @param AnnouncementManager $manager Announcement manager
     * @param AnnouncementRepositoryInterface $repository Announcement repository
     */
    public function __construct(AnnouncementManager $manager, AnnouncementRepositoryInterface $repository)
    {
        if (!is_admin()) {
            return;
        }

        $this->manager = $manager;
        $this->repository = $repository;

        $this->registerHooks();
    }

    /**
     * Register admin hooks
     */
    private function registerHooks(): void
    {
        add_filter(
            'manage_' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE . '_posts_columns',
            [$this, 'addCustomColumns']
        );
        add_action(
            'manage_' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE . '_posts_custom_column',
            [$this, 'displayCustomColumnContent'],
            10,
            2
        );
        add_filter(
            'manage_edit-' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE . '_sortable_columns',
            [$this, 'sortableCustomColumns']
        );
        add_action('pre_get_posts', [$this, 'customSortColumns']);
        add_action('admin_notices', [$this, 'displayAdminNotices']);
        add_action('admin_head', [$this, 'addAdminStyles']);
        add_action('save_post_' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE, [$this, 'updateStatusOnSave'], 10, 3);
        add_action('acf/save_post', [$this, 'updateStatusOnAcfSave'], 20);
        add_filter('post_row_actions', [$this, 'removeQuickEdit'], 10, 2);
    }

    /**
     * Disable Quick Edit on admin table
     *
     * @param array $actions Row actions
     * @param WP_Post $post Post object
     * @return array Modified actions
     */
    public function removeQuickEdit(array $actions, WP_Post $post): array
    {
        if ($post->post_type === TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
            unset($actions['inline hide-if-no-js']);
        }
        return $actions;
    }

    /**
     * Add admin styles
     */
    public function addAdminStyles(): void
    {
        ?>
        <style>
            .status-active { color: #46b450; }
            .status-expired { color: #dc3232; }
            .status-hidden { color: #ffb900; }
            .status-pending { color: #007cba; }
            .status-review { color: #f56e28; }
            .status-invalid { color: #dc3232; }
            .status-no-date { color: #999; }
            .column-announcement_status { width: 10%; }
            .column-announcement_hidden { width: 8%; }
            .column-announcement_end_date { width: 15%; }
        </style>
        <?php
    }

    /**
     * Add custom columns to the admin list
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public static function addCustomColumns(array $columns): array
    {
        $dateColumn = $columns['date'] ?? '';
        unset($columns['date']);

        $columns['announcement_status'] = __('Status', 'trumpet');
        $columns['announcement_start_date'] = __('Start Date', 'trumpet');
        $columns['announcement_end_date'] = __('End Date', 'trumpet');
        if ($dateColumn) {
            $columns['date'] = $dateColumn;
        }

        return $columns;
    }

    /**
     * Display custom column content
     *
     * @param string $column Column name
     * @param int $post_id Post ID
     */
    public function displayCustomColumnContent(string $column, int $post_id): void
    {
        try {
            switch ($column) {
                case 'announcement_end_date':
                    $end_date = get_field(TrumpetConfig::END_DATE_FIELD, $post_id);
                    echo esc_html($end_date);
                    break;
                case 'announcement_status':
                    self::displayAnnouncementStatus($post_id);
                    break;
                case 'announcement_start_date':
                    $start_date = get_field(TrumpetConfig::START_DISPLAY_FIELD, $post_id);
                    $this->updateStartDateSortMeta($post_id, $start_date);

                    if (!empty($start_date)) {
                        $start_date_obj = DateTime::createFromFormat('d/m/Y', $start_date);
                        $current_date = new DateTime();

                        if ($start_date_obj && $start_date_obj > $current_date) {
                            echo esc_html($start_date);
                        } else {
                            echo 'Started';
                        }
                    } else {
                        echo '<span class="status-no-date">—</span>';
                    }
                    break;
            }
        } catch (Exception $e) {
            $this->logError("Error displaying column content", $e);
        }
    }

    /**
     * Display announcement status in the admin table
     *
     * @param int $post_id Post ID
     */
    private static function displayAnnouncementStatus(int $post_id): void
    {
        $status = self::getAnnouncementStatus($post_id);

        $statusClasses = [
            'Review' => 'status-review',
            'Hidden' => 'status-hidden',
            'Pending' => 'status-pending',
            'No End Date' => 'status-no-date',
            'Invalid Date' => 'status-invalid',
            'Expired' => 'status-expired',
            'Active' => 'status-active',
        ];

        $class = $statusClasses[$status] ?? '';
        if ($class) {
            echo sprintf('<span class="%s">%s</span>', esc_attr($class), esc_html($status));
        } else {
            echo esc_html($status);
        }
    }

    /**
     * Get the status of an announcement and update its sorting meta
     *
     * @param int $post_id Post ID
     * @return string The status text
     */
    private static function getAnnouncementStatus(int $post_id): string
    {
        $end_date = get_field(TrumpetConfig::END_DATE_FIELD, $post_id);
        $start_date = get_field(TrumpetConfig::START_DISPLAY_FIELD, $post_id);
        $hidden = get_field(TrumpetConfig::HIDE_FIELD, $post_id);
        $post = get_post($post_id);
        $status = '';
        $sort_value = '';

        if ($post && $post->post_status === 'pending') {
            $status = 'Review';
            $sort_value = 'review';
        } elseif ($hidden) {
            $status = 'Hidden';
            $sort_value = 'hidden';
        } else {
            $current_date = new DateTime();

            if (!empty($start_date)) {
                $start_date_obj = DateTime::createFromFormat('d/m/Y', $start_date);
                if ($start_date_obj && $start_date_obj->format('Y-m-d') > $current_date->format('Y-m-d')) {
                    $status = 'Pending';
                    $sort_value = 'pending';
                }
            }

            if (empty($status)) {
                if (!$end_date) {
                    $status = 'No End Date';
                    $sort_value = 'no_end_date';
                } else {
                    $date_obj = DateTime::createFromFormat('d/m/Y', $end_date);
                    if (!$date_obj) {
                        $status = 'Invalid Date';
                        $sort_value = 'invalid';
                    } elseif ($date_obj->format('Y-m-d') < $current_date->format('Y-m-d')) {
                        $status = 'Expired';
                        $sort_value = 'expired';
                    } else {
                        $status = 'Active';
                        $sort_value = 'active';
                    }
                }
            }
        }

        update_post_meta($post_id, '_announcement_status_sort', $sort_value);

        return $status;
    }

    /**
     * Make custom columns sortable
     *
     * @param array $columns Existing sortable columns
     * @return array Modified sortable columns
     */
    public function sortableCustomColumns(array $columns): array
    {
        $columns['announcement_end_date'] = 'end_date';
        $columns['announcement_status'] = 'announcement_status';
        $columns['announcement_start_date'] = 'start_date';
        return $columns;
    }

    /**
     * Handle custom column sorting
     *
     * @param WP_Query $query WordPress query
     */
    public function customSortColumns(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $orderby = $query->get('orderby');

        switch ($orderby) {
            case 'end_date':
                $query->set('meta_key', TrumpetConfig::END_DATE_FIELD);
                $query->set('orderby', 'meta_value');
                break;

            case 'start_date':
                $query->set('meta_key', '_announcement_start_date_sort');
                $query->set('orderby', 'meta_value');

                $screen = get_current_screen();
                if ($screen && $screen->id === 'edit-' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
                    $this->updateAllStartDateSortMeta();
                }
                break;

            case 'announcement_status':
                $query->set('meta_key', '_announcement_status_sort');
                $query->set('orderby', 'meta_value');

                $screen = get_current_screen();
                if ($screen && $screen->id === 'edit-' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
                    $this->updateAllAnnouncementStatusMeta();
                }
                break;
        }
    }

    /**
     * Update status meta values for all announcements
     */
    private function updateAllAnnouncementStatusMeta(): void
    {
        try {
            $announcements = get_posts([
                'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
                'posts_per_page' => -1,
                'post_status' => 'publish'
            ]);

            foreach ($announcements as $announcement) {
                self::getAnnouncementStatus($announcement->ID);
            }
        } catch (Exception $e) {
            $this->logError("Error updating announcement status meta values", $e);
        }
    }

    /**
     * Display admin notices
     */
    public function displayAdminNotices(): void
    {
        global $pagenow, $post_type;

        if ($pagenow === 'edit.php' && $post_type === TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
            $expired = $this->getExpiredAnnouncementsCount();
            if ($expired > 0) {
                printf(
                    '<div class="notice notice-warning"><p>%s</p></div>',
                    sprintf(
                        esc_html(_n(
                            'There is %d expired announcement.',
                            'There are %d expired announcements.',
                            $expired,
                            'trumpet'
                        )),
                        $expired
                    )
                );
            }

            $review = $this->getReviewAnnouncementsCount();
            if ($review > 0) {
                printf(
                    '<div class="notice notice-warning" style="border-left-color: #f56e28;"><p>%s</p></div>',
                    sprintf(
                        esc_html(_n(
                            'There is %d announcement awaiting review.',
                            'There are %d announcements awaiting review.',
                            $review,
                            'trumpet'
                        )),
                        $review
                    )
                );
            }

            $pending = $this->getPendingAnnouncementsCount();
            if ($pending > 0) {
                printf(
                    '<div class="notice notice-info"><p>%s</p></div>',
                    sprintf(
                        esc_html(_n(
                            'There is %d pending announcement.',
                            'There are %d pending announcements.',
                            $pending,
                            'trumpet'
                        )),
                        $pending
                    )
                );
            }
        }
    }

    /**
     * Update status meta when a post is saved
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     * @param bool $update Whether this is an update
     */
    public function updateStatusOnSave(int $post_id, WP_Post $post, bool $update): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        self::getAnnouncementStatus($post_id);

        $start_date = get_field(TrumpetConfig::START_DISPLAY_FIELD, $post_id);
        $this->updateStartDateSortMeta($post_id, $start_date);
    }

    /**
     * Update status meta when ACF fields are saved
     *
     * @param int $post_id Post ID
     */
    public function updateStatusOnAcfSave(int $post_id): void
    {
        if (get_post_type($post_id) !== TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        self::getAnnouncementStatus($post_id);

        $start_date = get_field(TrumpetConfig::START_DISPLAY_FIELD, $post_id);
        $this->updateStartDateSortMeta($post_id, $start_date);
    }

    /**
     * Update start date sort meta values for all announcements
     */
    private function updateAllStartDateSortMeta(): void
    {
        try {
            $announcements = get_posts([
                'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
                'posts_per_page' => -1,
                'post_status' => 'publish'
            ]);

            foreach ($announcements as $announcement) {
                $start_date = get_field(TrumpetConfig::START_DISPLAY_FIELD, $announcement->ID);
                $this->updateStartDateSortMeta($announcement->ID, $start_date);
            }
        } catch (Exception $e) {
            $this->logError("Error updating start date sort meta values", $e);
        }
    }

    /**
     * Update start date sort meta for a specific post
     *
     * @param int $post_id Post ID
     * @param string|null $start_date Start date in d/m/Y format
     */
    private function updateStartDateSortMeta(int $post_id, ?string $start_date): void
    {
        $sort_value = '9999-99-99';

        if (!empty($start_date)) {
            $date_obj = DateTime::createFromFormat('d/m/Y', $start_date);
            if ($date_obj) {
                $sort_value = $date_obj->format('Y-m-d');
            }
        }

        update_post_meta($post_id, '_announcement_start_date_sort', $sort_value);
    }

    /**
     * Get count of expired announcements
     *
     * @return int
     */
    private function getExpiredAnnouncementsCount(): int
    {
        try {
            $announcements = $this->manager->getAnnouncements();
            $today = new DateTime();
            return count(array_filter($announcements, function ($announcement) use ($today) {
                return $announcement->getEndDate() && $announcement->getEndDate() < $today;
            }));
        } catch (Exception $e) {
            $this->logError("Error counting expired announcements", $e);
            return 0;
        }
    }

    /**
     * Get count of pending announcements
     *
     * @return int
     */
    private function getPendingAnnouncementsCount(): int
    {
        try {
            $announcements = $this->manager->getAnnouncements();
            $today = new DateTime();

            return count(array_filter($announcements, function ($announcement) use ($today) {
                return !$announcement->isHidden() &&
                       $announcement->getStartDisplayDate() &&
                       $announcement->getStartDisplayDate() > $today;
            }));
        } catch (Exception $e) {
            $this->logError("Error counting pending announcements", $e);
            return 0;
        }
    }

    /**
     * Get count of announcements in review status
     *
     * @return int
     */
    private function getReviewAnnouncementsCount(): int
    {
        try {
            $query = new WP_Query([
                'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
                'post_status' => 'pending',
                'posts_per_page' => -1,
                'fields' => 'ids'
            ]);

            return $query->found_posts;
        } catch (Exception $e) {
            $this->logError("Error counting announcements in review", $e);
            return 0;
        }
    }

    /**
     * Log errors with context
     *
     * @param string $context Error context
     * @param Exception $e Exception
     */
    private function logError(string $context, Exception $e): void
    {
        \Trumpet\Plugin::logError(sprintf(
            '[Trumpet Admin] %s: %s in %s:%d',
            $context,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}
