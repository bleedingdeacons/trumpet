<?php

declare(strict_types=1);

namespace Trumpet\FrontPage;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Exception;
use Unity\Meetings\Interfaces\MeetingRepository;

/**
 * Class FrontPageManager
 *
 * Registers a shortcode to display list of today's meetings.
 */
class FrontPageManager
{
    private MeetingRepository $repository;

    /**
     * Constructor.
     * Registers the shortcode.
     *
     * @param MeetingRepository $repository Meeting repository
     */
    public function __construct(MeetingRepository $repository)
    {
        $this->repository = $repository;
        add_shortcode('todays_meetings', [$this, 'render']);
    }

    /**
     * Render today's meetings.
     *
     * @return string HTML output.
     */
    public function render(): string
    {
        try {
            // Get current day (0 for Sunday, 1 for Monday, etc.)
            $current_day = intval(current_time('w'));
            $meetings = $this->repository->findAll(['day' => $current_day]);
            $list = '';

            foreach ($meetings as $meeting) {
                $location = ($meeting->isOnline()) ? 'Online' : $meeting->getLocation();

                $list .= '<li class="meeting">';
                $list .= '<div class="time">' . esc_html($meeting->getTime()) . ' - ';
                $list .= '<a href="' . esc_url($meeting->getUrl()) . '">' . esc_html($meeting->getName()) . '</a>';
                $list .= '</div>';
                $list .= '<div class="attendance-option">' . esc_html($location) . '</div>';
                $list .= '</li>';
            }

            if (empty($list)) {
                $list = '<li>No meetings scheduled for today.</li>';
            }

            return '<h1>Today\'s Meetings</h1><ul>' . $list . '</ul>';
        } catch (Exception $e) {
            \Trumpet\Plugin::logError('Error rendering todays_meetings shortcode: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return '<p>Sorry, an error occurred while retrieving today\'s meetings.</p>';
        }
    }
}
