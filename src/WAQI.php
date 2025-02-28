<?php declare(strict_types=1);
/**
 * This file is part of the WAQI (World Air Quality Index) package.
 *
 * Copyright (c) 2017 - 2019 AzuyaLabs
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Sacha Telgenhof <me@sachatelgenhof.com>
 */

namespace Azuyalabs\WAQI;

use Azuyalabs\WAQI\Exceptions\InvalidAccessToken;
use Azuyalabs\WAQI\Exceptions\QuotaExceeded;
use Azuyalabs\WAQI\Exceptions\UnknownStation;
use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;

/**
 * Class WAQI.
 */
class WAQI
{
    /**
     * The endpoint URL of the World Quality Index API.
     */
    private const API_ENDPOINT = 'https://api.waqi.info/api';

    /**
     * @var string World Air Quality access token
     */
    private $token;

    /**
     * @var \stdClass Raw response data received from the World Quality Index API.
     */
    private $raw_data;

    /**
     * WAQI class constructor.
     *
     * A World Air Quality access token is required to use this API. A token can be obtained by submitting a request at
     * http://aqicn.org/data-platform/token
     *
     * @param string $token World Air Quality access token
     */
    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * Retrieves the real-time Air Quality Index observation monitoring station name (or city name).
     *
     * If the $station argument is left out, the Air Quality Index observation is obtained of the nearest monitoring
     * station close to the user location (based on the user's public IP address)
     *
     * @param string $station name of the monitoring station (or city name). This parameter can be left blank to get the
     *                        observation of the nearest monitoring station close to the user location (based on the
     *                        user's public IP address)
     *
     * @return void
     * @throws QuotaExceeded
     * @throws InvalidAccessToken
     * @throws \UnexpectedValueException
     *
     * @throws UnknownStation
     */
    public function getObservationByStation(?string $station = null): void
    {
        $client = new Client(['base_uri' => self::API_ENDPOINT]);

        try {
            $response = $client->request('GET', 'feed/' . ($station ?? 'here') . '/', ['query' => 'token=' . $this->token]);
        } catch (ClientException $e) {
            echo Psr7\str($e->getRequest());
            echo Psr7\str($e->getResponse());
            exit();
        } catch (RequestException $e) {
            echo Psr7\str($e->getRequest());
            if ($e->hasResponse()) {
                echo Psr7\str($e->getResponse());
            }
            exit();
        } catch (GuzzleException $e) {
            echo $e->getMessage();
            exit();
        }

        $_response_body = \json_decode(Psr7\copy_to_string($response->getBody()), false);

        if ('ok' === $_response_body->status) {
            $this->raw_data = $_response_body->data;
        } elseif ('error' === $_response_body->status) {
            if (isset($_response_body->data)) {
                switch ($_response_body->data) {
                    case 'Unknown station':
                        throw new UnknownStation($station);
                    case 'Over quota':
                        throw new QuotaExceeded();
                    case 'Invalid key':
                        throw new InvalidAccessToken();
                }
            }
        }
    }

    public function getObservationByGeoLocation(float $latitude, float $longitude): void
    {
        $client = new Client(['base_uri' => self::API_ENDPOINT]);

        try {
            $response = $client->request('GET', 'feed/geo:' . $latitude . ';' . $longitude . '/', ['query' => 'token='.$this->token]);
        } catch (ClientException $e) {
            echo Psr7\str($e->getRequest());
            echo Psr7\str($e->getResponse());
            exit();
        } catch (RequestException $e) {
            echo Psr7\str($e->getRequest());
            if ($e->hasResponse()) {
                echo Psr7\str($e->getResponse());
            }
            exit();
        } catch (GuzzleException $e) {
            echo $e->getMessage();
            exit();
        }

        $_response_body = \json_decode(Psr7\copy_to_string($response->getBody()), false);

        if ($_response_body->status === 'ok') {
            $this->raw_data = $_response_body->data;
        } elseif ($_response_body->status === 'error') {
            if (isset($_response_body->data)) {
                switch ($_response_body->data) {
                    case 'Invalid key':
                        throw new InvalidAccessToken();
                    case 'Over quota':
                        throw new QuotaExceeded();
                }
            }
        }
    }

    /**
     * Returns information about the Air Quality Index measured at this monitoring station at the time of measurement.
     *
     * The array returned contains 4 elements:
     *  - 'aqi': the AQI level (which is defined by the monitoring stations' dominant pollution type)
     *  - 'pollution_level': a narrative describing the air pollution level
     *  - 'health_implications': a narrative describing the health implications associated with the measured pollution
     *                           level
     *  - 'cautionary_statement': a cautionary statement associated with the measured pollution level (only for PM2.5)
     *
     * @return array structure containing the Air Quality Index measured at this monitoring station at the time of
     *               measurement
     */
    public function getAQI(): array
    {
        $aqi = (int)$this->raw_data->aqi;

        $narrative_level = '';
        $narrative_health = '';
        $narrative_cautionary = '';

        // AQI Level: Good
        if ($aqi >= 0 && $aqi <= 50) {
            $narrative_level = 'Good';
            $narrative_health = 'Air quality is considered satisfactory, and air pollution poses little or no risk.';
            $narrative_cautionary = 'None';
        }

        // AQI Level: Moderate
        if ($aqi >= 51 && $aqi <= 100) {
            $narrative_level = 'Moderate';
            $narrative_health = 'Air quality is acceptable, however, for some pollutants there may be a moderate health concern for a very small number of people who are unusually sensitive to air pollution.';
            $narrative_cautionary = 'Active children and adults, and people with respiratory disease, such as asthma, should limit prolonged outdoor exertion.';
        }

        // AQI Level: Unhealthy for sensitive groups
        if ($aqi >= 101 && $aqi <= 150) {
            $narrative_level = 'Unhealthy for Sensitive Groups';
            $narrative_health = 'Members of sensitive groups may experience health effects. The general public is not likely to be affected.';
            $narrative_cautionary = 'Active children and adults, and people with respiratory disease, such as asthma, should limit prolonged outdoor exertion.';
        }

        // AQI Level: Unhealthy
        if ($aqi >= 151 && $aqi <= 200) {
            $narrative_level = 'Unhealthy';
            $narrative_health = 'Everyone may begin to experience health effects; members of sensitive groups may experience more serious health effects.';
            $narrative_cautionary = 'Active children and adults, and people with respiratory disease, such as asthma, should avoid prolonged outdoor exertion; everyone else, especially children, should limit prolonged outdoor exertion.';
        }

        // AQI Level: Very unhealthy
        if ($aqi >= 201 && $aqi <= 300) {
            $narrative_level = 'Very Unhealthy';
            $narrative_health = 'Health warnings of emergency conditions. The entire population is more likely to be affected.';
            $narrative_cautionary = 'Active children and adults, and people with respiratory disease, such as asthma, should avoid all outdoor exertion; everyone else, especially children, should limit prolonged outdoor exertion.';
        }

        // AQI Level: Hazardous
        if ($aqi >= 300) {
            $narrative_level = 'Hazardous';
            $narrative_health = 'Health alert; everyone may experience more serious health effects.';
            $narrative_cautionary = 'Everyone should avoid all outdoor exertion.';
        }

        return [
            'aqi' => (float)$aqi,
            'pollution_level' => $narrative_level,
            'health_implications' => $narrative_health,
            'cautionary_statement' => $narrative_cautionary,
        ];
    }

    /**
     * Returns the date/time the last measurement was taken.
     *
     * @return DateTime the date/time the last measurement was taken
     *
     * @throws \Exception
     */
    public function getMeasurementTime(): DateTime
    {
        return new DateTime($this->raw_data->time->s, new DateTimeZone($this->raw_data->time->tz));
    }

    /**
     * Returns information about this monitoring station.
     *
     * The array returned contains 4 elements:
     *  - 'id': the unique ID for this monitoring station
     *  - 'name': the name (or description) of this monitoring station
     *  - 'coordinates': the geographical coordinates of this monitoring station ('longitude' and 'latitude')
     *  - 'url': the URL of this monitoring station
     *
     * @return array structure containing information about this monitoring station
     */
    public function getMonitoringStation(): array
    {
        return [
            'id' => (int)$this->raw_data->idx,
            'name' => (string)\html_entity_decode($this->raw_data->city->name),
            'coordinates' => [
                'latitude' => (float)$this->raw_data->city->geo[0],
                'longitude' => (float)$this->raw_data->city->geo[1],
            ],
            'url' => (string)$this->raw_data->city->url,
        ];
    }

    /**
     * Returns a list of EPA attributions for this monitoring station.
     *
     * A list of one or more attributions is returned of which each contains a name and an URL attribute.
     *
     * @return array list of EPA attributions for this monitoring station
     */
    public function getAttributions(): array
    {
        return (array)\json_decode(\json_encode($this->raw_data->attributions), true);
    }

    /**
     * Returns the humidity (in %) measured at this monitoring station at the time of measurement.
     *
     * @return float|null the humidity (in %) measured at this monitoring station at the time of measurement.
     *                    If the monitoring station does not measure humidity levels, a 'null' value is returned.
     */
    public function getHumidity(): ?float
    {
        return $this->raw_data->iaqi->h->v ?? null;
    }

    /**
     * Returns the temperature (in degrees Celsius) measured at this monitoring station at the time of measurement.
     *
     * @return float|null the temperature (in degrees Celsius) measured at this monitoring station at the time of
     *                    measurement. If the monitoring station does not measure temperature levels, a 'null' value is
     *                    returned.
     */
    public function getTemperature(): ?float
    {
        return $this->raw_data->iaqi->t->v ?? null;
    }

    /**
     * Returns the barometric pressure (in millibars) measured at this monitoring station at the time of measurement.
     *
     * @return float|null the barometric pressure (in millibars) measured at this monitoring station at the time of
     *                    measurement. If the monitoring station does not barometric pressure levels, a 'null' value
     *                    is returned.
     */
    public function getPressure(): ?float
    {
        return $this->raw_data->iaqi->p->v ?? null;
    }

    /**
     * Returns the carbon monoxide (CO) level measured at this monitoring station at the time of measurement.
     *
     * CO concentration levels are typically expressed in Parts per million (PPM) or density, however the World Air
     * Quality levels is using the US EPA 0-500 AQI scale.
     *
     * @return float|null the carbon monoxide (CO) level measured at this monitoring station at the time of measurement.
     *                    If the monitoring station does not measure PM10 levels, a 'null' value is returned.
     */
    public function getCO(): ?float
    {
        return $this->raw_data->iaqi->co->v ?? null;
    }

    /**
     * Returns the nitrogen dioxide (NO2) level measured at this monitoring station at the time of measurement.
     *
     * NO2 concentration levels are typically expressed in Parts per million (PPM) or density, however the World Air
     * Quality levels is using the US EPA 0-500 AQI scale.
     *
     * @return float|null the nitrogen dioxide (NO2) level measured at this monitoring station at the time of
     *                    measurement. If the monitoring station does not measure PM10 levels, a 'null' value is
     *                    returned.
     */
    public function getNO2(): ?float
    {
        return $this->raw_data->iaqi->no2->v ?? null;
    }

    /**
     * Returns the ozone (O3) level measured at this monitoring station at the time of measurement.
     *
     * O3 concentration levels are typically expressed in Parts per million (PPM) or density, however the World Air
     * Quality levels is using the US EPA 0-500 AQI scale.
     *
     * @return float|null the ozone (O3) level measured at this monitoring station at the time of measurement. If the
     *                    monitoring station does not measure PM10 levels, a 'null' value is returned
     */
    public function getO3(): ?float
    {
        return $this->raw_data->iaqi->o3->v ?? null;
    }

    /**
     * Returns the level of respirable particulate matter, 10 micrometers or less (PM10), measured at this monitoring
     * station at the time of measurement.
     *
     * PM10 levels are typically expressed in Parts per million (PPM) or density, however the World Air
     * Quality levels is using the US EPA 0-500 AQI scale.
     *
     * @return float|null the level of particulate matter 10 micrometers or less (PM10), measured at this monitoring
     *                    station at the time of measurement. If the monitoring station does not measure PM10 levels,
     *                    a 'null' value is returned
     */
    public function getPM10(): ?float
    {
        return $this->raw_data->iaqi->pm10->v ?? null;
    }

    /**
     * Returns the level of fine particulate matter, 2.5 micrometers or less (PM2.5), measured at this monitoring
     * station at the time of measurement.
     *
     * PM2.5 levels are typically expressed in Parts per million (PPM) or density, however the World Air
     * Quality levels is using the US EPA 0-500 AQI scale.
     *
     * @return float|null the level of particulate matter 2.5 micrometers or less (PM2.5), measured at this monitoring
     *                    station at the time of measurement. If the monitoring station does not measure PM10 levels,
     *                    a 'null' value is returned
     */
    public function getPM25(): ?float
    {
        return $this->raw_data->iaqi->pm25->v ?? null;
    }

    /**
     * Returns the sulfur dioxide (SO2) level measured at this monitoring station at the time of measurement.
     *
     * SO2 concentration levels are typically expressed in Parts per million (PPM) or density, however the World Air
     * Quality levels is using the US EPA 0-500 AQI scale.
     *
     * @return float|null the sulfur dioxide (SO2) level measured at this monitoring station at the time of measurement.
     *                    If the monitoring station does not measure PM10 levels, a 'null' value is returned
     */
    public function getSO2(): ?float
    {
        return $this->raw_data->iaqi->so2->v ?? null;
    }

    /**
     * Returns the name of the primary pollutant at this monitoring station at the time of measurement.
     *
     * For example if the primary pollutant is PM2.5, the value of 'pm25' will be returned.
     *
     * @return string name of the primary pollutant at this monitoring station at the time of measurement
     */
    public function getPrimaryPollutant(): string
    {
        return (string)$this->raw_data->dominentpol;
    }
}
