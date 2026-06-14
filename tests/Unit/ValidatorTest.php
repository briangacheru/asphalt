<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Helpers\Validator;

class ValidatorTest extends TestCase
{
    public function testRequiredValidationPasses(): void
    {
        $validator = new Validator(['name' => 'John']);
        $validator->rules(['name' => 'required']);
        
        $this->assertTrue($validator->validate());
        $this->assertTrue($validator->passes());
        $this->assertFalse($validator->fails());
    }

    public function testRequiredValidationFails(): void
    {
        $validator = new Validator(['name' => '']);
        $validator->rules(['name' => 'required']);
        
        $this->assertFalse($validator->validate());
        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->fails());
        $this->assertNotEmpty($validator->errors());
    }

    public function testEmailValidationPasses(): void
    {
        $validator = new Validator(['email' => 'test@example.com']);
        $validator->rules(['email' => 'email']);
        
        $this->assertTrue($validator->validate());
    }

    public function testEmailValidationFails(): void
    {
        $validator = new Validator(['email' => 'invalid-email']);
        $validator->rules(['email' => 'email']);
        
        $this->assertFalse($validator->validate());
        $this->assertNotEmpty($validator->getFieldErrors('email'));
    }

    public function testMinLengthValidation(): void
    {
        $validator = new Validator(['password' => '123']);
        $validator->rules(['password' => 'min:5']);
        
        $this->assertFalse($validator->validate());
        $this->assertStringContainsString('at least 5 characters', $validator->firstError());
    }

    public function testMaxLengthValidation(): void
    {
        $validator = new Validator(['username' => 'verylongusername']);
        $validator->rules(['username' => 'max:10']);
        
        $this->assertFalse($validator->validate());
        $this->assertStringContainsString('must not exceed 10 characters', $validator->firstError());
    }

    public function testNumericValidation(): void
    {
        $validator = new Validator(['age' => '25']);
        $validator->rules(['age' => 'numeric']);
        
        $this->assertTrue($validator->validate());
    }

    public function testIntegerValidation(): void
    {
        $validator = new Validator(['count' => '42']);
        $validator->rules(['count' => 'integer']);
        
        $this->assertTrue($validator->validate());
    }

    public function testIntegerValidationFailsForFloat(): void
    {
        $validator = new Validator(['count' => '42.5']);
        $validator->rules(['count' => 'integer']);
        
        $this->assertFalse($validator->validate());
    }

    public function testBetweenValidation(): void
    {
        $validator = new Validator(['age' => '25']);
        $validator->rules(['age' => 'between:18,65']);
        
        $this->assertTrue($validator->validate());
    }

    public function testBetweenValidationFails(): void
    {
        $validator = new Validator(['age' => '70']);
        $validator->rules(['age' => 'between:18,65']);
        
        $this->assertFalse($validator->validate());
    }

    public function testInValidation(): void
    {
        $validator = new Validator(['status' => 'active']);
        $validator->rules(['status' => 'in:active,inactive,pending']);
        
        $this->assertTrue($validator->validate());
    }

    public function testInValidationFails(): void
    {
        $validator = new Validator(['status' => 'unknown']);
        $validator->rules(['status' => 'in:active,inactive,pending']);
        
        $this->assertFalse($validator->validate());
    }

    public function testDateValidation(): void
    {
        $validator = new Validator(['birthdate' => '2023-01-15']);
        $validator->rules(['birthdate' => 'date']);
        
        $this->assertTrue($validator->validate());
    }

    public function testDateValidationFails(): void
    {
        $validator = new Validator(['birthdate' => 'not-a-date']);
        $validator->rules(['birthdate' => 'date']);
        
        $this->assertFalse($validator->validate());
    }

    public function testUrlValidation(): void
    {
        $validator = new Validator(['website' => 'https://example.com']);
        $validator->rules(['website' => 'url']);
        
        $this->assertTrue($validator->validate());
    }

    public function testUrlValidationFails(): void
    {
        $validator = new Validator(['website' => 'not-a-url']);
        $validator->rules(['website' => 'url']);
        
        $this->assertFalse($validator->validate());
    }

    public function testConfirmedValidation(): void
    {
        $validator = new Validator([
            'password' => 'secret123',
            'password_confirmation' => 'secret123'
        ]);
        $validator->rules(['password' => 'confirmed']);
        
        $this->assertTrue($validator->validate());
    }

    public function testConfirmedValidationFails(): void
    {
        $validator = new Validator([
            'password' => 'secret123',
            'password_confirmation' => 'different'
        ]);
        $validator->rules(['password' => 'confirmed']);
        
        $this->assertFalse($validator->validate());
    }

    public function testAlphaValidation(): void
    {
        $validator = new Validator(['name' => 'JohnDoe']);
        $validator->rules(['name' => 'alpha']);
        
        $this->assertTrue($validator->validate());
    }

    public function testAlphaValidationFailsWithNumbers(): void
    {
        $validator = new Validator(['name' => 'John123']);
        $validator->rules(['name' => 'alpha']);
        
        $this->assertFalse($validator->validate());
    }

    public function testAlphaNumValidation(): void
    {
        $validator = new Validator(['username' => 'John123']);
        $validator->rules(['username' => 'alpha_num']);
        
        $this->assertTrue($validator->validate());
    }

    public function testAlphaNumValidationFailsWithSpecialChars(): void
    {
        $validator = new Validator(['username' => 'John@123']);
        $validator->rules(['username' => 'alpha_num']);
        
        $this->assertFalse($validator->validate());
    }

    public function testMultipleRulesOnSameField(): void
    {
        $validator = new Validator(['email' => 'invalid']);
        $validator->rules(['email' => 'required|email|min:5']);
        
        $this->assertFalse($validator->validate());
        $this->assertGreaterThanOrEqual(1, count($validator->getFieldErrors('email')));
    }

    public function testSanitizedReturnsTrimmedAndEscapedData(): void
    {
        $validator = new Validator(['name' => '  <script>alert("xss")</script>  ']);
        $sanitized = $validator->sanitized();
        
        $this->assertEquals('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $sanitized['name']);
    }

    public function testFirstErrorReturnsFirstMessage(): void
    {
        $validator = new Validator(['name' => '', 'email' => 'invalid']);
        $validator->rules(['name' => 'required', 'email' => 'email']);
        $validator->validate();
        
        $this->assertNotNull($validator->firstError());
    }

    public function testEmptyValueZeroPassesRequired(): void
    {
        $validator = new Validator(['count' => '0']);
        $validator->rules(['count' => 'required']);
        
        $this->assertTrue($validator->validate());
    }
}
