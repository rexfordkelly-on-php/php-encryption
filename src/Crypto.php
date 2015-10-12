<?php
namespace Defuse\Crypto;

use \Defuse\Crypto\Exception as Ex;

/*
 * PHP Encryption Library
 * Copyright (c) 2014-2015, Taylor Hornby <https://defuse.ca>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */
final class Crypto extends Core
{
    // Ciphertext format: [____VERSION____][____HMAC____][____IV____][____CIPHERTEXT____].
    // Legacy format: [____HMAC____][____IV____][____CIPHERTEXT____].
    const CIPHER_METHOD = 'aes-128-cbc';

    const LEGACY_VERSION = "\xD3\xF5\x01\x00";

    /**
     * Use this to generate a random encryption key.
     *
     * @return string
     */
    public static function createNewRandomKey()
    {
        $config = self::getVersionConfig(self::VERSION);
        return self::secureRandom($config['KEY_BYTE_SIZE']);
    }

    /**
     * Encrypts a message.
     *
     * $plaintext is the message to encrypt.
     * $key is the encryption key, a value generated by CreateNewRandomKey().
     * You MUST catch exceptions thrown by this function. See docs above.
     *
     * @param string $plaintext
     * @param string $key
     * @param boolean $raw_binary
     * @return string
     * @throws Ex\CannotPerformOperationException
     */
    public static function encrypt($plaintext, $key, $raw_binary = false)
    {
        self::runtimeTest();
        $config = self::getVersionConfig(parent::VERSION);

        if (self::ourStrlen($key) !== $config['KEY_BYTE_SIZE']) {
            throw new Ex\CannotPerformOperationException("Key is the wrong size.");
        }
        $salt = self::secureRandom($config['SALT_SIZE']);

        // Generate a sub-key for encryption.
        $ekey = self::HKDF(
            $config['HASH_FUNCTION'],
            $key,
            $config['KEY_BYTE_SIZE'],
            $config['ENCRYPTION_INFO'],
            $salt,
            $config
        );

        // Generate a sub-key for authentication and apply the HMAC.
        $akey = self::HKDF(
            $config['HASH_FUNCTION'],
            $key,
            $config['KEY_BYTE_SIZE'],
            $config['AUTHENTICATION_INFO'],
            $salt,
            $config
        );

        // Generate a random initialization vector.
        self::ensureFunctionExists("openssl_cipher_iv_length");
        $ivsize = \openssl_cipher_iv_length($config['CIPHER_METHOD']);
        if ($ivsize === false || $ivsize <= 0) {
            throw new Ex\CannotPerformOperationException(
                "Could not get the IV length from OpenSSL"
            );
        }
        $iv = self::secureRandom($ivsize);

        $ciphertext = $salt . $iv . self::plainEncrypt($plaintext, $ekey, $iv, $config);
        $auth = \hash_hmac($config['HASH_FUNCTION'], parent::VERSION . $ciphertext, $akey, true);

        // We're now appending the header as of 2.00
        $ciphertext = parent::VERSION . $auth . $ciphertext;

        if ($raw_binary) {
            return $ciphertext;
        }
        return self::binToHex($ciphertext);
    }

    /**
     * Decrypts a ciphertext.
     * $ciphertext is the ciphertext to decrypt.
     * $key is the key that the ciphertext was encrypted with.
     * You MUST catch exceptions thrown by this function. See docs above.
     *
     * @param string $ciphertext
     * @param string $key
     * @param boolean $raw_binary
     * @return type
     * @throws Ex\CannotPerformOperationException
     * @throws Ex\InvalidCiphertextException
     */
    public static function decrypt($ciphertext, $key, $raw_binary = false)
    {
        self::runtimeTest();
        if (!$raw_binary) {
            $ciphertext = self::hexToBin($ciphertext);
        }

        // Grab the header tag
        $version = self::ourSubstr($ciphertext, 0, parent::HEADER_VERSION_SIZE);

        // Load the configuration for this version
        $config = self::getVersionConfig($version);

        // Now let's operate on the remainder of the ciphertext as normal
        $ciphertext = self::ourSubstr($ciphertext, parent::HEADER_VERSION_SIZE, null);

        // Extract the HMAC from the front of the ciphertext.
        if (self::ourStrlen($ciphertext) <= $config['MAC_BYTE_SIZE']) {
            throw new Ex\InvalidCiphertextException(
                "Ciphertext is too short."
            );
        }
        $hmac = self::ourSubstr(
            $ciphertext, 
            0,
            $config['MAC_BYTE_SIZE']
        );
        if ($hmac === false) {
            throw new Ex\CannotPerformOperationException();
        }
        $salt = self::ourSubstr(
            $ciphertext,
            $config['MAC_BYTE_SIZE'], 
            $config['SALT_SIZE']
        );
        if ($salt === false) {
            throw new Ex\CannotPerformOperationException();
        }
        
        $ciphertext = self::ourSubstr(
            $ciphertext,
            $config['MAC_BYTE_SIZE'] + $config['SALT_SIZE']
        );
        if ($ciphertext === false) {
            throw new Ex\CannotPerformOperationException();
        }

        // Regenerate the same authentication sub-key.
        $akey = self::HKDF($config['HASH_FUNCTION'], $key, $config['KEY_BYTE_SIZE'], $config['AUTHENTICATION_INFO'], $salt, $config);

        if (self::verifyHMAC($hmac, $version . $salt . $ciphertext, $akey)) {
            // Regenerate the same encryption sub-key.
            $ekey = self::HKDF($config['HASH_FUNCTION'], $key, $config['KEY_BYTE_SIZE'], $config['ENCRYPTION_INFO'], $salt, $config);

            // Extract the initialization vector from the ciphertext.
            self::EnsureFunctionExists("openssl_cipher_iv_length");
            $ivsize = \openssl_cipher_iv_length($config['CIPHER_METHOD']);
            if ($ivsize === false || $ivsize <= 0) {
                throw new Ex\CannotPerformOperationException(
                    "Could not get the IV length from OpenSSL"
                );
            }
            if (self::ourStrlen($ciphertext) <= $ivsize) {
                throw new Ex\InvalidCiphertextException(
                    "Ciphertext is too short."
                );
            }
            $iv = self::ourSubstr($ciphertext, 0, $ivsize);
            if ($iv === false) {
                throw new Ex\CannotPerformOperationException();
            }
            $ciphertext = self::ourSubstr($ciphertext, $ivsize);
            if ($ciphertext === false) {
                throw new Ex\CannotPerformOperationException();
            }

            $plaintext = self::plainDecrypt($ciphertext, $ekey, $iv, $config);

            return $plaintext;
        } else {
            /*
             * We throw an exception instead of returning false because we want
             * a script that doesn't handle this condition to CRASH, instead
             * of thinking the ciphertext decrypted to the value false.
             */
            throw new Ex\InvalidCiphertextException(
                "Integrity check failed."
            );
        }
    }

    /**
     * Decrypts a ciphertext (legacy -- before version tagging)
     *
     * $ciphertext is the ciphertext to decrypt.
     * $key is the key that the ciphertext was encrypted with.
     * You MUST catch exceptions thrown by this function. See docs above.
     *
     * @param string $ciphertext
     * @param string $key
     * @return type
     * @throws Ex\CannotPerformOperationException
     * @throws Ex\InvalidCiphertextException
     */
    public static function legacyDecrypt($ciphertext, $key)
    {
        self::runtimeTest();
        $config = self::getVersionConfig(self::LEGACY_VERSION);

        // Extract the HMAC from the front of the ciphertext.
        if (self::ourStrlen($ciphertext) <= $config['MAC_BYTE_SIZE']) {
            throw new Ex\InvalidCiphertextException(
                "Ciphertext is too short."
            );
        }
        $hmac = self::ourSubstr($ciphertext, 0, $config['MAC_BYTE_SIZE']);
        if ($hmac === false) {
            throw new Ex\CannotPerformOperationException();
        }
        $ciphertext = self::ourSubstr($ciphertext, $config['MAC_BYTE_SIZE']);
        if ($ciphertext === false) {
            throw new Ex\CannotPerformOperationException();
        }

        // Regenerate the same authentication sub-key.
        $akey = self::HKDF(
            $config['HASH_FUNCTION'],
            $key,
            $config['KEY_BYTE_SIZE'],
            $config['AUTHENTICATION_INFO'],
            null,
            $config
        );

        if (self::verifyHMAC($hmac, $ciphertext, $akey)) {
            // Regenerate the same encryption sub-key.
            $ekey = self::HKDF(
                $config['HASH_FUNCTION'],
                $key,
                $config['KEY_BYTE_SIZE'],
                $config['ENCRYPTION_INFO'],
                null,
                $config
            );

            // Extract the initialization vector from the ciphertext.
            self::EnsureFunctionExists("openssl_cipher_iv_length");
            $ivsize = \openssl_cipher_iv_length($config['CIPHER_METHOD']);
            if ($ivsize === false || $ivsize <= 0) {
                throw new Ex\CannotPerformOperationException(
                    "Could not get the IV length from OpenSSL"
                );
            }
            if (self::ourStrlen($ciphertext) <= $ivsize) {
                throw new Ex\InvalidCiphertextException(
                    "Ciphertext is too short."
                );
            }
            $iv = self::ourSubstr($ciphertext, 0, $ivsize);
            if ($iv === false) {
                throw new Ex\CannotPerformOperationException();
            }
            $ciphertext = self::ourSubstr($ciphertext, $ivsize);
            if ($ciphertext === false) {
                throw new Ex\CannotPerformOperationException();
            }

            $plaintext = self::plainDecrypt($ciphertext, $ekey, $iv, $config);

            return $plaintext;
        } else {
            /*
             * We throw an exception instead of returning false because we want
             * a script that doesn't handle this condition to CRASH, instead
             * of thinking the ciphertext decrypted to the value false.
             */
            throw new Ex\InvalidCiphertextException(
                "Integrity check failed."
            );
        }
    }

    /*
     * Runs tests.
     * Raises CannotPerformOperationExceptionException or CryptoTestFailedExceptionException if
     * one of the tests fail. If any tests fails, your system is not capable of
     * performing encryption, so make sure you fail safe in that case.
     */
    public static function runtimeTest()
    {
        // 0: Tests haven't been run yet.
        // 1: Tests have passed.
        // 2: Tests are running right now.
        // 3: Tests have failed.
        static $test_state = 0;

        $config = self::getVersionConfig(parent::VERSION);

        if ($test_state === 1 || $test_state === 2) {
            return;
        }

        if ($test_state === 3) {
            /* If an intermittent problem caused a test to fail previously, we
             * want that to be indicated to the user with every call to this
             * library. This way, if the user first does something they really
             * don't care about, and just ignores all exceptions, they won't get 
             * screwed when they then start to use the library for something
             * they do care about. */
            throw new Ex\CryptoTestFailedException("Tests failed previously.");
        }

        try {
            $test_state = 2;

            self::ensureFunctionExists('openssl_get_cipher_methods');
            if (\in_array($config['CIPHER_METHOD'], \openssl_get_cipher_methods()) === false) {
                throw new Ex\CryptoTestFailedException("Cipher method not supported.");
            }

            self::AESTestVector($config);
            self::HMACTestVector($config);
            self::HKDFTestVector($config);

            self::testEncryptDecrypt($config);
            if (self::ourStrlen(self::createNewRandomKey()) != $config['KEY_BYTE_SIZE']) {
                throw new Ex\CryptoTestFailedException();
            }

            if ($config['ENCRYPTION_INFO'] == $config['AUTHENTICATION_INFO']) {
                throw new Ex\CryptoTestFailedException();
            }
        } catch (Ex\CryptoTestFailedException $ex) {
            // Do this, otherwise it will stay in the "tests are running" state.
            $test_state = 3;
            throw $ex;
        }

        // Change this to '0' make the tests always re-run (for benchmarking).
        $test_state = 1;
    }

    /**
     * Never call this method directly!
     *
     * Unauthenticated message encryption.
     *
     * @param string $plaintext
     * @param string $key
     * @param string $iv
     * @param array $config
     * @return string
     * @throws Ex\CannotPerformOperationException
     */
    private static function plainEncrypt($plaintext, $key, $iv, $config)
    {
        self::ensureConstantExists("OPENSSL_RAW_DATA");
        self::ensureFunctionExists("openssl_encrypt");
        $ciphertext = \openssl_encrypt(
            $plaintext,
            $config['CIPHER_METHOD'],
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($ciphertext === false) {
            throw new Ex\CannotPerformOperationException(
                "openssl_encrypt() failed."
            );
        }

        return $ciphertext;
    }

    /**
     * Never call this method directly!
     *
     * Unauthenticated message deryption.
     *
     * @param string $ciphertext
     * @param string $key
     * @param string $iv
     * @return string
     * @throws Ex\CannotPerformOperationException
     */
    private static function plainDecrypt($ciphertext, $key, $iv, $config)
    {
        self::ensureConstantExists("OPENSSL_RAW_DATA");
        self::ensureFunctionExists("openssl_decrypt");
        $plaintext = \openssl_decrypt(
            $ciphertext,
            $config['CIPHER_METHOD'],
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        if ($plaintext === false) {
            throw new Ex\CannotPerformOperationException(
                "openssl_decrypt() failed."
            );
        }

        return $plaintext;
    }

    /**
     * Verify a HMAC without crypto side-channels
     *
     * @staticvar boolean $native Use native hash_equals()?
     * @param string $correct_hmac HMAC string (raw binary)
     * @param string $message Ciphertext (raw binary)
     * @param string $key Authentication key (raw binary)
     * @return boolean
     * @throws Ex\CannotPerformOperationException
     */
    private static function verifyHMAC($correct_hmac, $message, $key)
    {
        static $config = null;
        if ($config === null) {
            $config = self::getVersionConfig(parent::VERSION);
        }
        $message_hmac = \hash_hmac($config['HASH_FUNCTION'], $message, $key, true);
        return self::hashEquals($correct_hmac, $message_hmac);
    }

    private static function testEncryptDecrypt($config)
    {
        $key = self::createNewRandomKey();
        $data = "EnCrYpT EvErYThInG\x00\x00";
        if (empty($config)) {
            $config = self::getVersionConfig(parent::VERSION);
        }

        // Make sure encrypting then decrypting doesn't change the message.
        $ciphertext = self::encrypt($data, $key, true);
        try {
            $decrypted = self::decrypt($ciphertext, $key, true);
        } catch (Ex\InvalidCiphertextException $ex) {
            // It's important to catch this and change it into a
            // CryptoTestFailedExceptionException, otherwise a test failure could trick
            // the user into thinking it's just an invalid ciphertext!
            throw new Ex\CryptoTestFailedException();
        }
        if ($decrypted !== $data) {
            throw new Ex\CryptoTestFailedException();
        }

        // Modifying the ciphertext: Appending a string.
        try {
            self::decrypt($ciphertext . "a", $key, true);
            throw new Ex\CryptoTestFailedException();
        } catch (Ex\InvalidCiphertextException $e) { /* expected */ }

        // Modifying the ciphertext: Changing an IV byte.
        try {
            $ciphertext[4] = chr((ord($ciphertext[4]) + 1) % 256);
            self::decrypt($ciphertext, $key, true);
            throw new Ex\CryptoTestFailedException();
        } catch (Ex\InvalidCiphertextException $e) { /* expected */ }

        // Decrypting with the wrong key.
        $key = self::createNewRandomKey();
        $data = "abcdef";
        $ciphertext = self::encrypt($data, $key, true);
        $wrong_key = self::createNewRandomKey();
        try {
            self::decrypt($ciphertext, $wrong_key, true);
            throw new Ex\CryptoTestFailedException();
        } catch (Ex\InvalidCiphertextException $e) { /* expected */ }

        // Ciphertext too small (shorter than HMAC).
        $key = self::createNewRandomKey();
        $ciphertext = \str_repeat("A", $config['MAC_BYTE_SIZE'] - 1);
        try {
            self::decrypt($ciphertext, $key, true);
            throw new Ex\CryptoTestFailedException();
        } catch (Ex\InvalidCiphertextException $e) { /* expected */ }
    }

    /**
     * Run-time testing
     *
     * @throws Ex\CryptoTestFailedException
     */
    private static function HKDFTestVector($config)
    {
        // HKDF test vectors from RFC 5869
        if (empty($config)) {
            $config = self::getVersionConfig(parent::VERSION);
        }

        // Test Case 1
        $ikm = \str_repeat("\x0b", 22);
        $salt = self::hexToBin("000102030405060708090a0b0c");
        $info = self::hexToBin("f0f1f2f3f4f5f6f7f8f9");
        $length = 42;
        $okm = self::hexToBin(
            "3cb25f25faacd57a90434f64d0362f2a" .
            "2d2d0a90cf1a5a4c5db02d56ecc4c5bf" .
            "34007208d5b887185865"
        );
        $computed_okm = self::HKDF("sha256", $ikm, $length, $info, $salt, $config);
        if ($computed_okm !== $okm) {
            throw new Ex\CryptoTestFailedException();
        }

        // Test Case 7
        $ikm = \str_repeat("\x0c", 22);
        $length = 42;
        $okm = self::hexToBin(
            "2c91117204d745f3500d636a62f64f0a" .
            "b3bae548aa53d423b0d1f27ebba6f5e5" .
            "673a081d70cce7acfc48"
        );
        $computed_okm = self::HKDF("sha1", $ikm, $length, '', null, $config);
        if ($computed_okm !== $okm) {
            throw new Ex\CryptoTestFailedException();
        }

    }

    /**
     * Run-Time tests
     *
     * @throws Ex\CryptoTestFailedException
     */
    private static function HMACTestVector($config)
    {
        if (empty($config)) {
            $config = self::getVersionConfig(parent::VERSION);
        }
        // HMAC test vector From RFC 4231 (Test Case 1)
        $key = \str_repeat("\x0b", 20);
        $data = "Hi There";
        $correct = "b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7";
        if (\hash_hmac($config['HASH_FUNCTION'], $data, $key) !== $correct) {
            throw new Ex\CryptoTestFailedException();
        }
    }

    /**
     * Run-time tests
     *
     * @throws Ex\CryptoTestFailedException
     */
    private static function AESTestVector($config)
    {
        // AES CBC mode test vector from NIST SP 800-38A
        $key = self::hexToBin("2b7e151628aed2a6abf7158809cf4f3c");
        $iv = self::hexToBin("000102030405060708090a0b0c0d0e0f");
        $plaintext = self::hexToBin(
            "6bc1bee22e409f96e93d7e117393172a" .
            "ae2d8a571e03ac9c9eb76fac45af8e51" .
            "30c81c46a35ce411e5fbc1191a0a52ef" .
            "f69f2445df4f9b17ad2b417be66c3710"
        );
        $ciphertext = self::hexToBin(
            "7649abac8119b246cee98e9b12e9197d" .
            "5086cb9b507219ee95db113a917678b2" .
            "73bed6b8e3c1743b7116e69e22229516" .
            "3ff1caa1681fac09120eca307586e1a7" .
            /* Block due to padding. Not from NIST test vector.
                Padding Block: 10101010101010101010101010101010
                Ciphertext:    3ff1caa1681fac09120eca307586e1a7
                           (+) 2fe1dab1780fbc19021eda206596f1b7
                           AES 8cb82807230e1321d3fae00d18cc2012

             */
            "8cb82807230e1321d3fae00d18cc2012"
        );

        $config = self::getVersionConfig(parent::VERSION);

        $computed_ciphertext = self::plainEncrypt($plaintext, $key, $iv, $config);
        if ($computed_ciphertext !== $ciphertext) {
            throw new Ex\CryptoTestFailedException();
        }

        $computed_plaintext = self::plainDecrypt($ciphertext, $key, $iv, $config);
        if ($computed_plaintext !== $plaintext) {
            throw new Ex\CryptoTestFailedException();
        }
    }

    /**
     * Take a 4-byte header and get meaningful version information out of it.
     * Common configuration options should go in Core.php
     *
     * @param string $header
     */
    protected static function getVersionConfig($header)
    {
        $valid = 0;
        $valid |= $header[0] ^ "\xDE";
        $valid |= $header[1] ^ "\xF5";
        $major = \ord($header[2]);
        $minor = \ord($header[3]);

        if ($major === 1) {
            return [
                'CIPHER_METHOD' => 'aes-128-cbc',
                'KEY_BYTE_SIZE' => 16,
                'HASH_FUNCTION' => 'sha256',
                'MAC_BYTE_SIZE' => 32,
                'ENCRYPTION_INFO' => 'DefusePHP|KeyForEncryption',
                'AUTHENTICATION_INFO' => 'DefusePHP|KeyForAuthentication'
            ];
        }
        $config = parent::getCoreVersionConfig($major, $minor, $valid);

        if ($major === 2) {
            switch ($minor) {
                case 0:
                    $config['CIPHER_METHOD'] = 'aes-128-ctr';
                    break;
            }
        }

        if ($valid !== 0) {
            throw new Ex\InvalidCiphertextException('Unknown ciphertext version');
        }
        return $config;
    }
}
