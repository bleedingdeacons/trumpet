<?php

declare(strict_types=1);

namespace Trumpet;

use Trumpet\Admin\TrumpetAdmin;
use Trumpet\Announcement\AnnouncementChangeTracker;
use Trumpet\Announcement\AnnouncementDeactivator;
use Trumpet\Announcement\AnnouncementManager;
use Trumpet\Announcement\AnnouncementRepository;
use Trumpet\Announcement\AnnouncementRepositoryInterface;
use Trumpet\Common\CacheInterface;
use Trumpet\Common\DependencyContainer;
use Trumpet\Common\WordPressCache;
use Trumpet\FrontPage\FrontPageManager;
use Trumpet\Meetings\MeetingFactoryInterface;
use Trumpet\Meetings\MeetingRepository;
use Trumpet\Meetings\MeetingRepositoryInterface;
use Trumpet\Meetings\TsmlMeetingFactory;

/**
 * Class TrumpetServiceProvider
 * Registers all announcement-related services
 */
class TrumpetServiceProvider
{
    /**
     * Register all services in the container
     *
     * @param DependencyContainer $container Dependency container
     */
    public function register(DependencyContainer $container): void
    {
        // Register Cache
        $container->register(CacheInterface::class, function () {
            return new WordPressCache();
        });

        // Register Meeting Factory
        $container->register(MeetingFactoryInterface::class, function () {
            return new TsmlMeetingFactory();
        });

        // Register Meeting Repository
        $container->register(MeetingRepositoryInterface::class, function (DependencyContainer $c) {
            return new MeetingRepository(
                $c->get(MeetingFactoryInterface::class),
                $c->get(CacheInterface::class)
            );
        });

        // Register AnnouncementChangeTracker
        $container->register(AnnouncementChangeTracker::class, function (DependencyContainer $c) {
            return new AnnouncementChangeTracker(
                $c->get(AnnouncementRepositoryInterface::class)
            );
        });

        // Register FrontPage Manager
        $container->register(FrontPageManager::class, function (DependencyContainer $c) {
            return new FrontPageManager(
                $c->get(MeetingRepositoryInterface::class)
            );
        });

        // Register Announcement Repository
        $container->register(AnnouncementRepositoryInterface::class, function (DependencyContainer $c) {
            return new AnnouncementRepository($c->get(CacheInterface::class));
        });

        // Register Announcement Manager
        $container->register(AnnouncementManager::class, function (DependencyContainer $c) {
            return new AnnouncementManager(
                $c->get(AnnouncementRepositoryInterface::class),
                $c->get(MeetingRepositoryInterface::class)
            );
        });

        // Register Admin
        $container->register(TrumpetAdmin::class, function (DependencyContainer $c) {
            return new TrumpetAdmin(
                $c->get(AnnouncementManager::class),
                $c->get(AnnouncementRepositoryInterface::class)
            );
        });

        // Register Deactivator
        $container->register(AnnouncementDeactivator::class, function (DependencyContainer $c) {
            return new AnnouncementDeactivator(
                $c->get(AnnouncementRepositoryInterface::class),
                $c->get(CacheInterface::class)
            );
        });
    }
}
