<?php

namespace App\Http\Controllers\Api\v1;

use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Http\Requests\Property\StorePropertyRequest;
use App\Http\Requests\Property\UpdatePropertyRequest;
use App\Http\Resources\Property\PropertyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyController extends Controller
{
    /**
     * GET /v1/agent/properties?page=1
     * Called by propertyApi to populate the dashboard grid layout.
     */
    public function index(): AnonymousResourceCollection
    {
        // Fetches your database seeders, paginated by 20 entries
        $properties = Property::latest()->paginate(20);

        return PropertyResource::collection($properties);
    }

    /**
     * POST /v1/agent/properties
     */
    public function store(StorePropertyRequest $request): JsonResponse
    {
        $property = Property::create([
            ...$request->validated(),
            'user_id' => Auth::id(),
            'agency_id' => Auth::user()->agency_id // Fixed: Changed 'agncy_id' to 'agency_id'
        ]);

        return (new PropertyResource($property))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /v1/agent/properties/{property}
     * Bulletproofed to handle both implicit model instances and raw IDs safely.
     */
    public function show(mixed $property): JsonResponse
    {
        // If route parameter binding failed or was named {id}, fetch manually
        if (!$property instanceof Property) {
            $property = Property::find($property);
        }

        if (!$property) {
            return response()->json([
                'success' => false,
                'message' => 'Property record not found in database.'
            ], 404);
        }

        // Force ensure status has a fallback value for the frontend canvas
        if (empty($property->status)) {
            $property->status = 'available';
        }

        return (new PropertyResource($property->load('images')))
            ->response();
    }

    /**
     * PUT /v1/agent/properties/{property}
     */
    public function update(UpdatePropertyRequest $request, mixed $property): JsonResponse
    {
        if (!$property instanceof Property) {
            $property = Property::findOrFail($property);
        }

        $this->authorize('update', $property);

        $property->update($request->validated());

        return (new PropertyResource($property->fresh()))
            ->response();
    }

    /**
     * DELETE /v1/agent/properties/{property}
     */
    public function destroy(mixed $property): JsonResponse
    {
        if (!$property instanceof Property) {
            $property = Property::findOrFail($property);
        }

        $this->authorize('delete', $property);

        $property->delete();

        return response()->json(['message' => 'Property deleted.'], 200);
    }
}