<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\Api\FileApiController;
use App\Controllers\Api\FolderApiController;
use App\Controllers\Api\MemberApiController;
use App\Controllers\Api\ShareLinkApiController;
use App\Controllers\Api\UploadApiController;
use App\Controllers\AuthController;
use App\Controllers\ExplorerController;
use App\Controllers\InvitationController;
use App\Controllers\ProfileController;
use App\Controllers\PublicShareController;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $uuid = '{uuid:[0-9a-fA-F-]{36}}';

    // ------------------------------------------------------------ Autenticación (público)
    $app->get('/login', [AuthController::class, 'showLogin']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->post('/logout', [AuthController::class, 'logout']);
    $app->get('/forgot-password', [AuthController::class, 'showForgot']);
    $app->post('/forgot-password', [AuthController::class, 'forgot']);
    $app->get('/reset-password/{token}', [AuthController::class, 'showReset']);
    $app->post('/reset-password/{token}', [AuthController::class, 'reset']);
    $app->post('/magic-link', [AuthController::class, 'requestMagic']);
    $app->get('/magic/{token}', [AuthController::class, 'showMagic']);
    $app->post('/magic/{token}', [AuthController::class, 'consumeMagic']);
    $app->get('/invitation/{token}', [InvitationController::class, 'show']);
    $app->post('/invitation/{token}', [InvitationController::class, 'accept']);

    // ------------------------------------------------------------ Enlaces públicos (sin cuenta)
    $app->group('/s/{token}', function (RouteCollectorProxy $g) use ($uuid): void {
        $g->get('', [PublicShareController::class, 'show']);
        $g->post('/unlock', [PublicShareController::class, 'unlock']);
        $g->get('/api/folder', [PublicShareController::class, 'contents']);
        $g->get("/api/folders/$uuid", [PublicShareController::class, 'contents']);
        $g->get("/files/$uuid/download", [PublicShareController::class, 'download']);
        $g->get("/files/$uuid/preview", [PublicShareController::class, 'preview']);
        $g->post('/zip-info', [PublicShareController::class, 'zipInfo']);
        $g->post('/download-zip', [PublicShareController::class, 'downloadZip']);
    });

    // ------------------------------------------------------------ Aplicación (requiere sesión)
    $app->group('', function (RouteCollectorProxy $g) use ($uuid): void {
        $g->get('/', [ExplorerController::class, 'home']);
        $g->get("/folders/$uuid", [ExplorerController::class, 'folder']);
        $g->get('/profile', [ProfileController::class, 'show']);
        $g->post('/profile', [ProfileController::class, 'update']);
        $g->post('/profile/password', [ProfileController::class, 'password']);

        $g->group('/admin', function (RouteCollectorProxy $a) use ($uuid): void {
            $a->get('', [AdminController::class, 'index']);
            $a->get('/users', [AdminController::class, 'users']);
            $a->post('/users/invite', [AdminController::class, 'inviteUsers']);
            $a->post("/users/$uuid", [AdminController::class, 'updateUser']);
            $a->get('/entities', [AdminController::class, 'entities']);
            $a->post('/entities', [AdminController::class, 'createEntity']);
            $a->post("/entities/$uuid", [AdminController::class, 'updateEntity']);
            $a->post("/entities/$uuid/delete", [AdminController::class, 'deleteEntity']);
            $a->get('/settings', [AdminController::class, 'settings']);
            $a->post('/settings', [AdminController::class, 'saveSettings']);
            $a->get('/activity', [AdminController::class, 'activity']);
            $a->get('/storage', [AdminController::class, 'storage']);
        })->add(AdminMiddleware::class);

        // -------------------------------------------------------- API JSON
        $g->group('/api', function (RouteCollectorProxy $api) use ($uuid): void {
            $api->get('/folders', [FolderApiController::class, 'home']);
            $api->post('/folders', [FolderApiController::class, 'createRoot']);
            $api->get("/folders/$uuid", [FolderApiController::class, 'show']);
            $api->patch("/folders/$uuid", [FolderApiController::class, 'update']);
            $api->delete("/folders/$uuid", [FolderApiController::class, 'delete']);
            $api->post("/folders/$uuid/folders", [FolderApiController::class, 'createSubfolder']);
            $api->post("/folders/$uuid/delete-items", [FolderApiController::class, 'deleteItems']);
            $api->post("/folders/$uuid/zip-info", [FolderApiController::class, 'zipInfo']);
            $api->post("/folders/$uuid/download-zip", [FolderApiController::class, 'downloadZip']);
            $api->get('/search', [FolderApiController::class, 'search']);

            $api->get("/folders/$uuid/members", [MemberApiController::class, 'list']);
            $api->post("/folders/$uuid/members", [MemberApiController::class, 'invite']);
            $api->patch("/folders/$uuid/members/{member:[0-9a-fA-F-]{36}}", [MemberApiController::class, 'update']);
            $api->delete("/folders/$uuid/members/{member:[0-9a-fA-F-]{36}}", [MemberApiController::class, 'remove']);
            $api->delete("/invitations/$uuid", [MemberApiController::class, 'cancelInvitation']);

            $api->get("/folders/$uuid/share-links", [ShareLinkApiController::class, 'list']);
            $api->post("/folders/$uuid/share-links", [ShareLinkApiController::class, 'create']);
            $api->delete("/share-links/$uuid", [ShareLinkApiController::class, 'revoke']);

            $api->post("/folders/$uuid/files/init", [UploadApiController::class, 'init']);
            $api->post("/folders/$uuid/notify-upload", [UploadApiController::class, 'notify']);
            $api->post("/uploads/$uuid/parts/sign", [UploadApiController::class, 'signParts']);
            $api->post("/uploads/$uuid/complete", [UploadApiController::class, 'complete']);
            $api->get("/uploads/$uuid", [UploadApiController::class, 'status']);
            $api->delete("/uploads/$uuid", [UploadApiController::class, 'abort']);
            $api->post("/files/$uuid/confirm", [UploadApiController::class, 'confirm']);
            $api->post("/files/$uuid/abort", [UploadApiController::class, 'abortSingle']);
            $api->post("/files/$uuid/sign", [UploadApiController::class, 'signSingle']);

            $api->get("/files/$uuid", [FileApiController::class, 'show']);
            $api->patch("/files/$uuid", [FileApiController::class, 'rename']);
            $api->delete("/files/$uuid", [FileApiController::class, 'delete']);
            $api->get("/files/$uuid/download", [FileApiController::class, 'download']);
            $api->get("/files/$uuid/preview", [FileApiController::class, 'preview']);
        });
    })->add(AuthMiddleware::class);
};
