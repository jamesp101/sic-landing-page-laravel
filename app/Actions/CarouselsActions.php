<?php

namespace App\Actions;

use App\Admin\ActionInput;
use App\Admin\DepartmentAccess;
use App\Carousel\CarouselAdmin;
use App\Carousel\Overlay;
use App\Carousel\OverlaySchema;
use App\Carousel\Slides;
use App\Models\CarouselItem;
use App\Storage\ImageFile;
use App\Storage\Storage;
use App\Support\Authz;
use App\Support\RequestError;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** `carousels.*` actions (multipart for create/update, JSON for the rest). Every result is `{ slide }` except reorder and delete. */
class CarouselsActions
{
    private const OVERLAY_TOO_LARGE = 'The slide design is too large.';

    private const VIDEO_TOO_LONG = 'The video link is too long.';

    public function create(Request $request): array
    {
        $input = ActionInput::validate(
            $request,
            ['image' => 'raw', 'overlay' => 'text', 'video' => 'text', 'departmentId' => 'int', 'showOnMain' => 'bool'],
            [
                'image' => ['required', ...ImageFile::imageFile(Storage::limit('image'), 'slide')],
                'overlay' => ['nullable', 'string', 'max:60000'],
                'video' => ['nullable', 'string', 'max:500'],
                'departmentId' => ['nullable', 'integer'],
                'showOnMain' => ['required', 'boolean'],
            ],
            ['image.required' => ImageFile::MISSING, 'overlay.max' => self::OVERLAY_TOO_LARGE, 'video.max' => self::VIDEO_TOO_LONG],
        );
        $actor = Authz::requireUser($request->user());
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
    public function update(Request $request): array
    {
        $input = ActionInput::validate(
            $request,
            ['id' => 'int', 'overlay' => 'text', 'video' => 'raw', 'image' => 'raw'],
            [
                'id' => ['required', 'integer'],
                'overlay' => ['string', 'max:60000'],
                'video' => ['nullable', 'string', 'max:500'],
                'image' => ['nullable', ...ImageFile::imageFile(Storage::limit('image'), 'slide')],
            ],
            ['overlay.max' => self::OVERLAY_TOO_LARGE, 'video.max' => self::VIDEO_TOO_LONG],
        );
        $actor = Authz::requireUser($request->user());
        /** @var CarouselItem $row */
        $row = DepartmentAccess::loadScoped($actor, 'carouselItem', $input['id']);
        $content = $this->overlayData($input['overlay']);

        // '' removes the video; omitted keeps it.
        $video = $request->has('video') ? $this->videoData((string) ($input['video'] ?? ''), $row->id) : null;
        $image = $input['image'] instanceof UploadedFile ? $this->image($input['image']) : null;
        $saved = CarouselAdmin::updateSlide($row, ['content' => $content, 'image' => $image, 'video' => $video]);

        return ['slide' => Slides::toDesignerSlide($saved)];
    }

    /** Where a slide is shown: the main page and/or one department. Department users cannot move it out of their department. */
    public function placement(Request $request): array
    {
        $input = ActionInput::validate(
            $request,
            ['id' => 'int', 'showOnMain' => 'bool', 'departmentId' => 'int'],
            ['id' => ['required', 'integer'], 'showOnMain' => ['nullable', 'boolean'], 'departmentId' => ['nullable', 'integer']],
        );
        $actor = Authz::requireUser($request->user());
        /** @var CarouselItem $row */
        $row = DepartmentAccess::loadScoped($actor, 'carouselItem', $input['id']);
        $departmentId = $request->has('departmentId')
            ? DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null)
            : $row->department_id;
        $row->update(['department_id' => $departmentId, 'show_on_main' => $input['showOnMain'] ?? $row->show_on_main]);

        return ['slide' => Slides::toDesignerSlide($row)];
    }

    /** Copies a slide (image file included) right after the original. */
    public function duplicate(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => 'int'], ['id' => ['required', 'integer']]);
        $actor = Authz::requireUser($request->user());
        /** @var CarouselItem $row */
        $row = DepartmentAccess::loadScoped($actor, 'carouselItem', $input['id']);

        return ['slide' => Slides::toDesignerSlide(CarouselAdmin::duplicateSlide($row))];
    }

    /** Persists a new slide sequence (drag and drop). `ids` lists the reordered slides in their new order. */
    public function reorder(Request $request): array
    {
        $input = ActionInput::validate(
            $request,
            ['ids' => 'raw'],
            ['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['required', self::jsonInt()]],
            [
                'ids.required' => 'Too small: expected array to have >=1 items',
                'ids.min' => 'Too small: expected array to have >=1 items',
                'ids.max' => 'Too big: expected array to have <=500 items',
            ],
        );
        $actor = Authz::requireUser($request->user());
        $ids = array_map('intval', array_values($input['ids']));
        if (count(array_unique($ids)) !== count($ids)) {
            throw RequestError::badRequest('Duplicate slides in the order.');
        }
        $rows = DepartmentAccess::loadScopedMany($actor, 'carouselItem', $ids);

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

    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => 'int'], ['id' => ['required', 'integer']]);
        $actor = Authz::requireUser($request->user());
        /** @var CarouselItem $row */
        $row = DepartmentAccess::loadScoped($actor, 'carouselItem', $input['id']);

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

    /** zod `z.number().int()` on JSON input: numeric strings are rejected. */
    private static function jsonInt(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_int($value)) {
                $fail('Invalid input: expected number, received '.get_debug_type($value));
            }
        };
    }
}
