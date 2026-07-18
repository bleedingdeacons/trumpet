<?php

declare(strict_types=1);

namespace Trumpet\Announcement;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Exception;
use Trumpet\Exception\AnnouncementException;
use Unity\Meetings\Interfaces\MeetingRepository;

/**
 * Class AnnouncementManager
 *
 * Class for managing announcements
 */
class AnnouncementManager
{
    private AnnouncementRepositoryInterface $repository;
    private MeetingRepository $meetingRepository;

    /**
     * Constructor
     *
     * @param AnnouncementRepositoryInterface $repository Announcement repository
     * @param MeetingRepository $meetings Meeting repository
     */
    public function __construct(AnnouncementRepositoryInterface $repository, MeetingRepository $meetings)
    {
        $this->repository = $repository;
        $this->meetingRepository = $meetings;

        // Register hooks
        add_shortcode('list_announcements', [$this, 'generateAnnouncementsList']);
        add_shortcode('announcements_indicator', [$this, 'renderNewIndicator']);
        add_action('wp_head', [$this, 'addStyles']);
        add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
    }

    /**
     * Render the "new announcements" indicator banner.
     *
     * Exposed as the [announcements_indicator] shortcode so it can be placed
     * anywhere on the page — typically above the [list_announcements] output.
     * It reads "Announcements" by default; the front-end script changes the
     * label to a count of the announcements published since the visitor last
     * scrolled the list into view (e.g. "3 New Announcements"), counting down
     * as they are scrolled to and reverting once none are left.
     *
     * @param array  $atts    Shortcode attributes
     * @param string $content Shortcode content
     * @return string
     */
    public function renderNewIndicator(array $atts = [], string $content = ''): string
    {
        wp_enqueue_script('trumpet-announcements');

        return '<div class="announcements-new-banner" role="status" aria-live="polite">'
            . '<span class="announcements-new-banner__text">Announcements</span>'
            . '</div>';
    }

    /**
     * Register front-end assets.
     *
     * Registered (not enqueued) here so the script is only loaded on pages
     * that actually render the [announcements_indicator] shortcode, which
     * calls wp_enqueue_script('trumpet-announcements') when it runs.
     */
    public function registerAssets(): void
    {
        wp_register_script(
            'trumpet-announcements',
            TRUMPET_PLUGIN_URL . 'assets/js/announcements.js',
            [],
            TRUMPET_VERSION,
            true
        );
    }

    /**
     * Get all announcements
     *
     * @return array
     */
    public function getAnnouncements(): array
    {
        try {
            return $this->repository->findAll();
        } catch (AnnouncementException $e) {
            return [];
        }
    }

    /**
     * Generate announcements list HTML
     *
     * @param array $atts Shortcode attributes
     * @param string $content Shortcode content
     * @return string
     */
    public function generateAnnouncementsList(array $atts = [], string $content = ''): string
    {
        try {
            $activeAnnouncements = $this->repository->findActive();

            $output = '<div class="announcements-container">';
            $output .= '<h1 id="announcements">Announcements</h1>';

            if (empty($activeAnnouncements)) {
                $output .= '<p>No current announcements.</p>';
            } else {
                foreach ($activeAnnouncements as $announcement) {
                    $output .= $this->renderSingleAnnouncement($announcement);
                }
            }

            $output .= $this->renderFooter();
            $output .= '</div>';

            return $output;
        } catch (AnnouncementException $e) {
            return '<div class="error-message">Unable to load announcements. Please try again later.</div>';
        }
    }

    /**
     * Render a single announcement
     *
     * @param Announcement $announcement Announcement to render
     * @return string
     */
    private function renderSingleAnnouncement(Announcement $announcement): string
    {
        $output = sprintf(
            '<div class="announcement" data-published="%d">',
            $announcement->getPublishedTimestamp()
        );

        // Title with edit link for admins
        $title_output = sprintf('<h2>%s</h2>', esc_html($announcement->getTitle()));

        if (current_user_can('edit_post', $announcement->getId())) {
            $edit_link = get_edit_post_link($announcement->getId());
            $title_output = sprintf(
                '<h2>%s <a href="%s" class="announcement-edit-link" title="Edit this announcement"><span class="dashicons dashicons-edit"></span></a></h2>',
                esc_html($announcement->getTitle()),
                esc_url($edit_link)
            );
        }

        $output .= $title_output;

        // Featured Image
        if ($thumbnail_id = get_post_thumbnail_id($announcement->getId())) {
            $image_data = wp_get_attachment_image_src($thumbnail_id, 'large');
            if ($image_data) {
                $output .= sprintf(
                    '<div class="announcement-featured-image">
                    <img src="%s" alt="%s" width="%s" height="%s" loading="lazy">
                </div>',
                    esc_url($image_data[0]),
                    esc_attr($announcement->getTitle()),
                    esc_attr($image_data[1]),
                    esc_attr($image_data[2])
                );
            }
        }

        // Content with proper media handling
        $content = $announcement->getBody();
        $content = do_shortcode($content);

        global $wp_embed;
        $content = $wp_embed->autoembed($content);
        $content = $wp_embed->run_shortcode($content);

        $content = $this->processContentImages($content);
        $content = apply_filters('the_content', $content);

        $output .= sprintf(
            '<div class="announcement-content">%s</div>',
            $content
        );

        // Location/Map
        //
        // Gated on hasValidLocation() rather than getShowMap() alone. The map
        // is drawn from data-lat/data-lng attributes, so an announcement with
        // the map switched on and no coordinates entered emitted
        // data-lat="" data-lng="" and left the front end to fail on it.
        // hasValidLocation() already requires showMap, so this only ever
        // removes maps that had nothing to point at.
        if ($announcement->hasValidLocation()) {
            $output .= $this->renderMap($announcement->getLocation());
        }

        // Related Meetings
        if (!empty($announcement->getRelatedMeeting())) {
            $output .= $this->renderRelatedMeetings($announcement->getRelatedMeeting());
        }

        // Meta information
        $output .= $this->renderAnnouncementMeta($announcement);

        $output .= '</div>';

        return $output;
    }

    /**
     * Process images in content
     *
     * @param string $content Content to process
     * @return string
     */
    private function processContentImages(string $content): string
    {
        preg_match_all('/<img[^>]+>/', $content, $matches);

        foreach ($matches[0] as $img) {
            $orig_img = $img;

            $img = str_replace('<img', '<img class="img-fluid"', $img);

            if (strpos($img, 'loading=') === false) {
                $img = str_replace('<img', '<img loading="lazy"', $img);
            }

            if (strpos($content, '<figure') === false) {
                $img = sprintf('<figure class="announcement-image">%s</figure>', $img);
            }

            $content = str_replace($orig_img, $img, $content);
        }

        return $content;
    }

    /**
     * Render map
     *
     * @param array $location Location data
     * @return string
     */
    private function renderMap(array $location): string
    {
        return sprintf(
            '<div class="address">%s</div>
            <div class="acf-map" data-zoom="16">
                <div class="marker" data-lat="%s" data-lng="%s"></div>
            </div>',
            esc_html($location['address'] ?? ''),
            esc_attr($location['lat']),
            esc_attr($location['lng'])
        );
    }

    /**
     * Render related meetings
     *
     * @param array $list List of meeting IDs
     * @return string
     */
    private function renderRelatedMeetings(array $list): string
    {
        $output = '<div class="meeting_list">';

        foreach ($list as $item) {

            $meeting = $this->meetingRepository->findById((int) $item);

            if (!empty($meeting)) {

                if ($meeting->isOnline()) {
                    $type = '<span class="online dashicons dashicons-admin-site-alt3"></span>';
                } else {
                    $type = '<span class="face2face dashicons dashicons-groups"></span>';
                }

                $output .= sprintf(
                    '<div class="meeting_link">
                    <a class="link_light" href="%s">%s %s</a>
                </div>',
                    esc_url($meeting->getUrl()),
                    esc_html($meeting->getName()),
                    $type
                );
            }
        }

        $output .= '</div>';
        return $output;
    }

    /**
     * Render footer
     *
     * @return string
     */
    private function renderFooter(): string
    {
        return '<div class="announcements-footer">
            <p>To submit an announcement, please email 
               <a href="mailto:support@aa-bristol.org">support@aa-bristol.org</a>
            </p>
        </div>';
    }

    /**
     * Render announcement meta
     *
     * @param Announcement $announcement Announcement
     * @return string
     */
    private function renderAnnouncementMeta(Announcement $announcement): string
    {
        $output = '<div class="announcement-meta">';

        if ($announcement->getEndDate()) {
            $output .= sprintf(
                '<div class="announcement-date">Valid until: %s</div>',
                esc_html($announcement->getFormattedEndDate('F j, Y'))
            );
        }

        $output .= '</div>';

        return $output;
    }

    /**
     * Add necessary styles for announcements
     */
    public function addStyles(): void
    {
        ?>
        <style>
            .announcement {
                margin-bottom: 2em;
                padding: 1em;
                border: 1px solid #ddd;
                border-radius: 4px;
            }

            .announcement.announcement--unseen {
                border-color: #0073aa;
                box-shadow: 0 0 0 1px #0073aa;
            }

            .announcement h2 {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .announcement-edit-link {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                color: #0073aa;
                text-decoration: none;
                font-size: 0.8em;
                opacity: 0.7;
                transition: all 0.2s ease;
            }

            .announcement-edit-link:hover {
                opacity: 1;
                color: #00a0d2;
            }

            .announcement-edit-link .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }

            .announcement-featured-image {
                margin-bottom: 1em;
            }

            .announcement-featured-image img {
                max-width: 100%;
                height: auto;
                display: block;
            }

            .announcement-content {
                overflow: hidden;
                width: 100%;
            }

            .announcement-content img {
                max-width: 100%;
                height: auto;
            }

            .announcement-content figure {
                margin: 1em 0;
            }

            .announcement-content iframe,
            .announcement-content video {
                max-width: 100%;
                height: auto;
                aspect-ratio: 16/9;
            }

            .video-container {
                position: relative;
                padding-bottom: 56.25%;
                height: 0;
                overflow: hidden;
            }

            .video-container iframe,
            .video-container video {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
            }

            .announcement-meta {
                margin-top: 1em;
                padding-top: 1em;
                border-top: 1px solid #eee;
                font-size: 0.9em;
                color: #666;
            }

            .announcement-image {
                display: block;
                margin: 0 auto;
                max-width: 100%;
                height: auto;
            }

            .announcement-image .img-fluid {
                display: block !important;
                margin: auto !important;
                max-width: 100%;
                height: auto;
            }

            @media (max-width: 768px) {
                .announcement {
                    padding: 0.5em;
                }

                .announcement-featured-image {
                    margin-left: -0.5em;
                    margin-right: -0.5em;
                }
            }
        </style>
        <?php
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
            '[Announcement Plugin] %s: %s in %s:%d',
            $context,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}
