<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $id
 * @property int $endpoint_id
 * @property string $method
 * @property string $raw_query
 * @property array<string, list<string|null>> $headers
 * @property string $body
 * @property list<string> $redacted_header_names
 * @property bool $body_redacted
 * @property Carbon $received_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Delivery> $deliveries
 * @property-read int|null $deliveries_count
 * @property-read \App\Models\Endpoint $endpoint
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CapturedRequest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CapturedRequest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CapturedRequest query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CapturedRequest whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CapturedRequest whereBodyRedacted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CapturedRequest whereEndpointId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CapturedRequest whereHeaders($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CapturedRequest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CapturedRequest whereMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CapturedRequest whereRawQuery($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CapturedRequest whereReceivedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CapturedRequest whereRedactedHeaderNames($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperCapturedRequest {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $destination_id
 * @property int $captured_request_id
 * @property string $status
 * @property int $attempt_count
 * @property int|null $response_status
 * @property string|null $last_error
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property-read \App\Models\CapturedRequest|null $capturedRequest
 * @property-read \App\Models\Destination|null $destination
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Delivery newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Delivery newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Delivery query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperDelivery {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $endpoint_id
 * @property string $url
 * @property bool $is_active
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Delivery> $deliveries
 * @property-read int|null $deliveries_count
 * @property-read \App\Models\Endpoint|null $endpoint
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Destination newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Destination newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Destination query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperDestination {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $public_id
 * @property int $retention_days
 * @property list<string> $redacted_header_names
 * @property bool $body_redaction
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CapturedRequest> $capturedRequests
 * @property-read int|null $captured_requests_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Destination> $destinations
 * @property-read int|null $destinations_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Endpoint newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Endpoint newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Endpoint query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Endpoint whereBodyRedaction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Endpoint whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Endpoint whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Endpoint whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Endpoint wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Endpoint whereRedactedHeaderNames($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Endpoint whereRetentionDays($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Endpoint whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Endpoint whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperEndpoint {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Endpoint> $endpoints
 * @property-read int|null $endpoints_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperTeam {}
}
