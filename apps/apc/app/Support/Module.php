<?php declare(strict_types=1);

namespace Apc\Support;

use Apc\Controllers\{AdminController,AttachmentController,CalendarController,CurriculumAdminController,CurriculumController,DashboardController,DeliveryController,PlanController,ReportController};
use Apc\Repositories\{AccessRepository,AuditRepository,CurriculumRepository,DeliveryRepository,EventRepository,PlanRepository,SettingsRepository};
use Apc\Services\{AttachmentService,AuthorizationService,CalendarImporter,CurriculumImporter,CurriculumService,DeliveryService,EventService,EventWindow,PlanService,SettingsService};
use PDO;
use PreConselho\Integration\SecretariaApiClient;
use Shared\Support\View;

final class Module
{
    public readonly DashboardController $dashboard;
    public readonly PlanController $plans;
    public readonly DeliveryController $deliveries;
    public readonly AttachmentController $attachments;
    public readonly AdminController $admin;
    public readonly ReportController $reports;
    public readonly CurriculumController $curriculum;
    public readonly CurriculumAdminController $curriculumAdmin;
    public readonly CalendarController $calendar;

    public function __construct(PDO $apcDb,PDO $mainDb,SecretariaApiClient $api,string $root)
    {
        $view=new View($root.'/apps/apc/resources/views');$access=new AccessRepository($mainDb);$audit=new AuditRepository($apcDb);$events=new EventRepository($apcDb);$plans=new PlanRepository($apcDb);$curriculum=new CurriculumRepository($apcDb);$deliveryRepository=new DeliveryRepository($apcDb);$settings=new SettingsRepository($apcDb);$authorization=new AuthorizationService($plans,$access);$eventWindow=new EventWindow();
        $planService=new PlanService($plans,$events,$access,$audit,$authorization,$api,$curriculum,$eventWindow);$curriculumService=new CurriculumService($curriculum,$audit);$curriculumImporter=new CurriculumImporter($curriculum,$audit,$root.'/apps/apc/resources/curriculo');$calendarImporter=new CalendarImporter($events,$audit,$root.'/apps/apc/resources/calendario/eventos_ee_sao_jose_2026.csv');$deliveryService=new DeliveryService($plans,$deliveryRepository,$settings,$audit,$authorization,$api,$eventWindow);$attachmentService=AttachmentService::fromEnvironment($deliveryRepository,$audit,$authorization,$root,$eventWindow);$eventService=new EventService($events,$audit,$eventWindow);$settingsService=new SettingsService($settings,$audit,$apcDb);
        $this->dashboard=new DashboardController($plans,$events,$access,$view,$eventWindow);$this->plans=new PlanController($planService,$authorization,$events,$access,$view,$curriculum,$eventWindow);$this->deliveries=new DeliveryController($deliveryService,$view);$this->attachments=new AttachmentController($attachmentService);$this->admin=new AdminController($events,$settings,$audit,$eventService,$settingsService,$calendarImporter,$view);$this->reports=new ReportController($plans,$events,$access,$view);$this->curriculum=new CurriculumController($curriculum);$this->curriculumAdmin=new CurriculumAdminController($curriculum,$curriculumService,$curriculumImporter,$view);$this->calendar=new CalendarController($events,$plans,$view,$eventWindow);
    }
}
