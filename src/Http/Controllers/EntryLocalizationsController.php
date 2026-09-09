<?php

namespace Tv2regionerne\StatamicPrivateApi\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades;
use Statamic\Http\Controllers\CP\Collections\EntriesController as CpController;
use Statamic\Http\Resources\API\EntryResource;
use Tv2regionerne\StatamicPrivateApi\Traits\VerifiesPrivateAPI;

/**
 * Read and write a single localization of an entry.
 *
 * Entry localizations are separate entries linked to an origin via origin().
 * makeLocalization() creates one when it doesn't exist yet.
 *
 * Writes are delegated to the CP controller so blueprint validation,
 * processing and authorization all apply, matching CollectionEntriesController.
 */
class EntryLocalizationsController extends ApiController
{
    use VerifiesPrivateAPI;

    /**
     * GET /collections/{collection}/entries/{entry}/localizations
     *
     * Lists which sites this entry exists in. Accepts the id of any
     * localization; resolves to the origin first.
     */
    public function index($collection, $entry)
    {
        $collection = $this->collectionFromHandle($collection);
        $root = $this->rootEntry($entry, $collection);

        $this->authorize('view', $root);

        $sites = collect([$root])->merge($root->descendants())
            ->map(fn ($e) => [
                'site' => $e->locale(),
                'id' => $e->id(),
                'slug' => $e->slug(),
                'published' => $e->published(),
                'is_origin' => $e->id() === $root->id(),
            ])->values();

        return response()->json(['data' => $sites]);
    }

    /**
     * GET /collections/{collection}/entries/{entry}/localizations/{site}
     */
    public function show($collection, $entry, $site)
    {
        $collection = $this->collectionFromHandle($collection);
        $root = $this->rootEntry($entry, $collection);
        $site = $this->siteFromHandle($site, $collection);

        $localized = $root->in($site->handle());

        abort_unless($localized, 404, 'No localization for that site.');

        $this->authorize('view', $localized);

        return app(EntryResource::class)::make($localized);
    }

    /**
     * PATCH /collections/{collection}/entries/{entry}/localizations/{site}
     *
     * Creates the localization when it doesn't exist yet. The payload is
     * merged over the current values before it goes through the CP controller,
     * so a partial payload only changes the keys it contains.
     */
    public function update(Request $request, $collection, $entry, $site)
    {
        $collection = $this->collectionFromHandle($collection);
        $root = $this->rootEntry($entry, $collection);
        $site = $this->siteFromHandle($site, $collection);

        if (! $localized = $root->in($site->handle())) {
            $this->authorize('create', [EntryContract::class, $collection, $site]);

            $localized = $root->makeLocalization($site);
            $localized->save();
        }

        $payloadKeys = collect($request->except(['id', '_localized']))->keys();

        $request->headers->add(['accept' => 'application/json']);

        $originalData = collect(
            (new CpController($request))->edit($request, $collection, $localized)->get('values')
        )->filter();

        $request->replace($originalData->merge($request->all())->all());

        // The CP controller replaces a localization's data with only the keys
        // listed in `_localized`, so it must contain the existing overrides for
        // this site plus the keys in the payload — otherwise fields would be
        // reset to the origin value (and dated collections would error).
        $request->merge(['_localized' => $localized->data()->keys()
            ->merge($payloadKeys)
            ->unique()->values()->all()]);

        try {
            $response = (new CpController($request))->update($request, $collection, $localized);
        } catch (ValidationException $e) {
            return $this->returnValidationErrors($e);
        }

        if (! $id = Arr::get($response, 'data.id')) {
            abort(403);
        }

        return app(EntryResource::class)::make(Facades\Entry::find($id));
    }

    /**
     * GET /collections/{collection}/entries/{entry}/localizations/{site}/values
     *
     * Returns the publish-form values for one localization in the RAW stored
     * format (the same data the CP edit screen uses), so clients can
     * round-trip structured fields like bard and replicator: read these
     * values, change only the text nodes, and PATCH the result back.
     *
     * The regular show endpoint returns augmented values (e.g. bard as HTML),
     * which cannot be written back through blueprint processing.
     */
    public function values(Request $request, $collection, $entry, $site)
    {
        $collection = $this->collectionFromHandle($collection);
        $root = $this->rootEntry($entry, $collection);
        $site = $this->siteFromHandle($site, $collection);

        $localized = $root->in($site->handle());

        abort_unless($localized, 404, 'No localization for that site.');

        $this->authorize('view', $localized);

        $request->headers->add(['accept' => 'application/json']);

        $editData = (new CpController($request))->edit($request, $collection, $localized);

        $payload = [
            'site' => $site->handle(),
            'values' => data_get($editData, 'values', []),
            'localizedFields' => data_get($editData, 'localizedFields', []),
            'hasOrigin' => data_get($editData, 'hasOrigin', false),
            'originValues' => data_get($editData, 'originValues'),
        ];

        if ($request->boolean('with_blueprint')) {
            $payload['blueprint'] = data_get($editData, 'blueprint');
        }

        return response()->json(['data' => $payload]);
    }

    private function collectionFromHandle($collection)
    {
        $collection = is_string($collection) ? Facades\Collection::find($collection) : $collection;

        if (! $collection) {
            abort(404);
        }

        abort_if(! $this->resourcesAllowed('collections', $collection->handle()), 404);

        return $collection;
    }

    /**
     * Resolve the entry and return its origin, so any localization id works.
     */
    private function rootEntry($entry, $collection)
    {
        $entry = is_string($entry) ? Facades\Entry::find($entry) : $entry;

        if (! $entry || $entry->collection()->id() !== $collection->id()) {
            abort(404);
        }

        return $entry->origin() ?? $entry;
    }

    /**
     * A site can exist globally while a collection is only enabled for a
     * subset of sites, so check against the collection rather than Site::all().
     */
    private function siteFromHandle($handle, $collection)
    {
        $site = Facades\Site::get($handle);

        abort_unless($site, 422, "Unknown site handle: {$handle}");

        abort_if(
            ! $collection->sites()->contains($site->handle()),
            422,
            "Collection {$collection->handle()} is not enabled for site {$site->handle()}."
        );

        return $site;
    }
}
