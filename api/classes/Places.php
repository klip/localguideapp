<?php

/**
 * Points on the map as the API stores and compares them: validation of a
 * `{ latitude, longitude }` from a request, and SQL for MySQL's geographic
 * points (SRID 4326, written latitude first — `axis-order=lat-long`).
 * Distances use `ST_Distance_Sphere` (metres, on a sphere — plenty for "how
 * far is this guide").
 */
final class Places
{
    /**
     * `{ latitude, longitude }` from a request as two floats, or null when
     * absent. Both must be numbers in range; anything else is a 400.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public static function readPoint($value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }
        $latitude = is_array($value) ? ($value['latitude'] ?? null) : null;
        $longitude = is_array($value) ? ($value['longitude'] ?? null) : null;
        if (!is_int($latitude) && !is_float($latitude) || !is_int($longitude) && !is_float($longitude)
            || abs($latitude) > 90 || abs($longitude) > 180) {
            throw new RuntimeException($field . ' needs a latitude (±90) and a longitude (±180).');
        }

        return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude];
    }

    /** A literal point for SQL. Coordinates are floats from `readPoint()`, so nothing to escape. */
    public static function pointSql(array $point): string
    {
        return "ST_GeomFromText('POINT(" . sprintf('%.6F %.6F', $point['latitude'], $point['longitude'])
            . ")', 4326, 'axis-order=lat-long')";
    }

    /** Kilometres between a point column and a point, as SQL. */
    public static function distanceKmSql(string $column, array $point): string
    {
        return 'ST_Distance_Sphere(' . $column . ', ' . self::pointSql($point) . ') / 1000';
    }
}
