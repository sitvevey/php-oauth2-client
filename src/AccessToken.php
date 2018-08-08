<?php

/*
 * Copyright (c) 2017, 2018 François Kooman <fkooman@tuxed.net>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace fkooman\OAuth\Client;

use DateInterval;
use DateTime;
use Exception;
use fkooman\OAuth\Client\Exception\AccessTokenException;

class AccessToken
{
    /** @var string */
    private $providerId;

    /** @var \DateTime */
    private $issuedAt;

    /** @var string */
    private $accessToken;

    /** @var string */
    private $tokenType;

    /** @var null|int */
    private $expiresIn;

    /** @var null|string */
    private $refreshToken;

    /** @var null|string */
    private $scope;

    /**
     * @param string      $providerId
     * @param string      $issuedAt
     * @param string      $accessToken
     * @param string      $tokenType
     * @param null|int    $expiresIn
     * @param null|string $refreshToken
     * @param null|string $scope
     */
    public function __construct($providerId, $issuedAt, $accessToken, $tokenType, $expiresIn = null, $refreshToken = null, $scope = null)
    {
        $this->setProviderId($providerId);
        $this->setIssuedAt($issuedAt);
        $this->setAccessToken($accessToken);
        $this->setTokenType($tokenType);
        $this->setExpiresIn($expiresIn);
        $this->setRefreshToken($refreshToken);
        $this->setScope($scope);
    }

    /**
     * @param Provider      $provider
     * @param \DateTime     $dateTime
     * @param TokenResponse $tokenResponse
     * @param string        $scope
     *
     * @return AccessToken
     */
    public static function fromCodeResponse(Provider $provider, DateTime $dateTime, TokenResponse $tokenResponse, $scope)
    {
        return new self(
            $provider->getProviderId(),
            $dateTime->format('Y-m-d H:i:s'),
            $tokenResponse->getAccessToken(),
            $tokenResponse->getTokenType(),
            $tokenResponse->getExpiresIn(),
            $tokenResponse->getRefreshToken(),
            // if the scope was not part of the response, add the request scope,
            // because according to the RFC, if the scope is ommitted the requested
            // scope was granted!
            null !== $tokenResponse->getScope() ? $tokenResponse->getScope() : $scope
        );
    }

    /**
     * @param Provider      $provider
     * @param \DateTime     $dateTime
     * @param TokenResponse $tokenResponse
     * @param AccessToken   $accessToken   to steal the old scope and refresh_token from!
     *
     * @return AccessToken
     */
    public static function fromRefreshResponse(Provider $provider, DateTime $dateTime, TokenResponse $tokenResponse, self $accessToken)
    {
        return new self(
            $provider->getProviderId(),
            $dateTime->format('Y-m-d H:i:s'),
            $tokenResponse->getAccessToken(),
            $tokenResponse->getTokenType(),
            $tokenResponse->getExpiresIn(),
            // if the refresh_token is not part of the response, we wil reuse the
            // existing refresh_token for future refresh_token requests
            null !== $tokenResponse->getRefreshToken() ? $tokenResponse->getRefreshToken() : $accessToken->getRefreshToken(),
            // if the scope is not part of the response, add the request scope,
            // because according to the RFC, if the scope is ommitted the requested
            // scope was granted!
            null !== $tokenResponse->getScope() ? $tokenResponse->getScope() : $accessToken->getScope()
        );
    }

    /**
     * @return string
     */
    public function getProviderId()
    {
        return $this->providerId;
    }

    /**
     * @return \DateTime
     */
    public function getIssuedAt()
    {
        return $this->issuedAt;
    }

    /**
     * @return string
     *
     * @see https://tools.ietf.org/html/rfc6749#section-5.1
     */
    public function getToken()
    {
        return $this->accessToken;
    }

    /**
     * @return string
     *
     * @see https://tools.ietf.org/html/rfc6749#section-7.1
     */
    public function getTokenType()
    {
        return $this->tokenType;
    }

    /**
     * @return null|int
     *
     * @see https://tools.ietf.org/html/rfc6749#section-5.1
     */
    public function getExpiresIn()
    {
        return $this->expiresIn;
    }

    /**
     * @return null|string the refresh token
     *
     * @see https://tools.ietf.org/html/rfc6749#section-1.5
     */
    public function getRefreshToken()
    {
        return $this->refreshToken;
    }

    /**
     * @return null|string
     *
     * @see https://tools.ietf.org/html/rfc6749#section-3.3
     */
    public function getScope()
    {
        return $this->scope;
    }

    /**
     * @param \DateTime $dateTime
     *
     * @return bool
     */
    public function isExpired(DateTime $dateTime)
    {
        if (null === $this->getExpiresIn()) {
            // if no expiry was indicated, assume it is valid
            return false;
        }

        // check to see if issuedAt + expiresIn > provided DateTime
        $expiresAt = clone $this->issuedAt;
        $expiresAt->add(new DateInterval(\sprintf('PT%dS', $this->getExpiresIn())));

        return $dateTime >= $expiresAt;
    }

    /**
     * @param string $providerId
     *
     * @return void
     */
    private function setProviderId($providerId)
    {
        $this->providerId = $providerId;
    }

    /**
     * @param string $issuedAt
     *
     * @return void
     */
    private function setIssuedAt($issuedAt)
    {
        if (1 !== \preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $issuedAt)) {
            throw new AccessTokenException('invalid "expires_at" (syntax)');
        }

        // make sure it is actually a valid date
        try {
            $this->issuedAt = new DateTime($issuedAt);
        } catch (Exception $e) {
            throw new AccessTokenException(
                \sprintf('invalid "expires_at": %s', $e->getMessage())
            );
        }
    }

    /**
     * @param string $accessToken
     *
     * @return void
     */
    private function setAccessToken($accessToken)
    {
        // access-token = 1*VSCHAR
        // VSCHAR       = %x20-7E
        if (1 !== \preg_match('/^[\x20-\x7E]+$/', $accessToken)) {
            throw new AccessTokenException('invalid "access_token"');
        }
        $this->accessToken = $accessToken;
    }

    /**
     * @param string $tokenType
     *
     * @return void
     */
    private function setTokenType($tokenType)
    {
        if ('bearer' !== $tokenType && 'Bearer' !== $tokenType) {
            throw new AccessTokenException('unsupported "token_type"');
        }
        $this->tokenType = $tokenType;
    }

    /**
     * @param null|int $expiresIn
     *
     * @return void
     */
    private function setExpiresIn($expiresIn)
    {
        if (null !== $expiresIn) {
            if (0 >= $expiresIn) {
                throw new AccessTokenException('invalid "expires_in"');
            }
        }
        $this->expiresIn = $expiresIn;
    }

    /**
     * @param null|string $refreshToken
     *
     * @return void
     */
    private function setRefreshToken($refreshToken)
    {
        if (null !== $refreshToken) {
            // refresh-token = 1*VSCHAR
            // VSCHAR        = %x20-7E
            if (1 !== \preg_match('/^[\x20-\x7E]+$/', $refreshToken)) {
                throw new AccessTokenException('invalid "refresh_token"');
            }
        }
        $this->refreshToken = $refreshToken;
    }

    /**
     * @param null|string $scope
     *
     * @return void
     */
    private function setScope($scope)
    {
        if (null !== $scope) {
            // scope       = scope-token *( SP scope-token )
            // scope-token = 1*NQCHAR
            // NQCHAR      = %x21 / %x23-5B / %x5D-7E
            foreach (\explode(' ', $scope) as $scopeToken) {
                if (1 !== \preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/', $scopeToken)) {
                    throw new AccessTokenException('invalid "scope"');
                }
            }
        }
        $this->scope = $scope;
    }
}
