<?php

namespace App\Actions;

use App\Admin\ActionField;
use App\Admin\ActionInput;
use App\Admin\DepartmentAccess;
use App\Carousel\CarouselAdmin;
use App\Carousel\Overlay;
use App\Carousel\OverlaySchema;
use App\Carousel\Slides;
use App\Models\CarouselItem;
use App\Storage\ImageFile;
use App\Storage\Storage;
use App\Support\RequestError;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** `carousels.*` actions (multipart for create/update, JSON for the rest). Every result is `{ slide }` except reorder and delete. */
class CarouselsActions
{
    private const OVERLAY_TOO_LARGE = 'The slide design is too large.';

    private const VIDEO_TOO_LONG = 'The video link is too long.';

    #[Action(Access::User)]
    public function create(Request $request): array
    {
        $input = ActionInput::validate($request, [
            'image' => ActionField::raw(['required', ...ImageFile::imageFile(Storage::limit('image'), 'slide')], ['required' => ImageFile::MISSING]),
            'overlay' => ActionField::text(['nullable', 'string', 'max:60000'], ['max' => self::OVERLAY_TOO_LARGE]),
            'video' => ActionField::text(['nullable', 'string', 'max:500'], ['max' => self::VIDEO_TOO_LONG]),
            'departmentId' => ActionField::int(['nullable', 'integer']),
            'showOnMain' => ActionField::bool(['required', 'boolean']),
        ]);
        $actor = $request->user();
        $departmentId = DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null);
        $content = ($input['overlay'] ?? '') !== ''
            ? $this->overlayData($input['overlay'])
            : ['overlay' => null, 'title' => null, 'subtitle' => null, 'linkUrl' => null];

        $video = $this->videoData($input['video'] ?? '');
        $row = CarouselAdmin::createSlide([
            'content' => $content,
            'image' => $this->image($input['image']),
            'video' => $video,
            'departmentId' => $departmentId,
            'showOnMain' => $input['showOnMain'],
        ]);

        return ['slide' => Slides::toDesignerSlide($row)];
    }

    /** Saves the design (and optionally a replacement image) of one slide. */
    #[Action(Access::User)]
    public function update(Request $request): array
    {
        $input = ActionInput::validate($request, [
            'id' => ActionField::id(),
            'overlay' => ActionField::text(['string', 'max:60000'], ['max' => self::OVERLAY_TOO_LARGE]),
            'video' => ActionField::raw(['nullable', 'string', 'max:500'], ['max' => self::VIDEO_TOO_LONG]),
            'image' => ActionField::raw(['nullable', ...ImageFile::imageFile(Storage::limit('image'), 'slide')]),
        ]);
        $actor = $request->user();
        /** @var CarouselItem $row */
        $row = DepartmentAccess::loadScoped($actor, CarouselItem::class, $input['id']);
        $content = $this->overlayData($input['overlay']);

        // '' removes the video; omitted keeps it.
        $video = $request->has('video') ? $this->videoData((string) ($input['video'] ?? ''), $row->id) : null;
        $image = $input['image'] instanceof UploadedFile ? $this->image($input['image']) : null;
        $saved = CarouselAdmin::updateSlide($row, ['content' => $content, 'image' => $image, 'video' => $video]);

        return ['slide' => Slides::toDesignerSlide($saved)];
    }

    /** Where a slide is shown: the main page and/or one department. Department users cannot move it out of their department. */
    #[Action(Access::User)]
    public function placement(Request $request): array
    {
        $input = ActionInput::validate($request, [
            'id' => ActionField::id(),
            'showOnMain' => ActionField::bool(['nullable', 'boolean']),
            'departmentId' => ActionField::int(['nullable', 'integer']),
        ]);
        $actor = $request->user();
        /** @var CarouselItem $row */
        $row = DepartmentAccess::loadScoped($actor, CarouselItem::class, $input['id']);
        $departmentId = $request->has('departmentId')
            ? DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null)
            : $row->department_id;
        $row->update(['department_id' => $departmentId, 'show_on_main' => $input['showOnMain'] ?? $row->show_on_main]);

        return ['slide' => Slides::toDesignerSlide($row)];
    }

    /** Copies a slide (image file included) right after the original. */
    #[Action(Access::User)]
    public function duplicate(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => ActionField::id()]);
        $actor = $request->user();
        /** @var CarouselItem $row */
        $row = DepartmentAccess::loadScoped($actor, CarouselItem::class, $input['id']);

        return ['slide' => Slides::toDesignerSlide(CarouselAdmin::duplicateSlide($row))];
    }

    /** Persists a new slide sequence (drag and drop). `ids` lists the reordered slides in their new order. */
    #[Action(Access::User)]
    public function reorder(Request $request): array
    {
        $input = ActionInput::validate($request, ['ids' => ActionField::list(500, ['*' => ['required', ActionField::jsonInt()]])]);
        $actor = $request->user();
        $ids = array_map('intval', array_values($input['ids']));
        if (count(array_unique($ids)) !== count($ids)) {
            throw RequestError::badRequest('Duplicate slides in the order.');
        }
        $rows = DepartmentAccess::loadScopedMany($actor, CarouselItem::class, $ids);

        $orders = CarouselAdmin::assignOrders(array_map(fn (CarouselItem $row): int => $row->order, $rows));
        DB::transaction(function () use ($ids, $orders): void {
            foreach ($ids as $index => $id) {
                CarouselItem::query()->whereKey($id)->update(['order' => $orders[$index]]);
            }
        });

        $byId = [];
        foreach ($ids as $index => $id) {
            $byId[(string) $id] = $orders[$index];
        }

        return ['orders' => (object) $byId];
    }

    #[Action(Access::User)]
    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => ActionField::id()]);
        $actor = $request->user();
        /** @var CarouselItem $row */
        $row = DepartmentAccess::loadScoped($actor, CarouselItem::class, $input['id']);

        return CarouselAdmin::deleteSlide($row);
    }

    /** Validates the designer document and derives the legacy title / subtitle / link columns from it. */
    private function overlayData(string $raw): array
    {
        $result = OverlaySchema::parseOverlayStrict($raw);
        if (! $result['ok']) {
            throw RequestError::badRequest($result['error']);
        }

        return ['overlay' => Overlay::serializeOverlay($result['layers'], $result['mobile']), ...Overlay::legacyFields($result['layers'])];
    }

    /** Validates the designer's `video` field (our upload or a YouTube link) and checks the slide may own that file. */
    private function videoData(string $raw, ?int $slideId = null): array
    {
        $result = CarouselAdmin::claimSlideVideo($raw, $slideId);
        if (! $result['ok']) {
            throw RequestError::badRequest($result['error']);
        }

        return $result['video'];
    }

    private function image(UploadedFile $file): UploadedFile
    {
        return ImageFile::upload($file, 'slide');
    }
}
