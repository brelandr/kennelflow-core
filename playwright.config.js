/**
 * Playwright — KennelFlow Core E2E (`tests/e2e`).
 *
 * @package KennelFlow
 */

const { defineConfig, devices } = require( '@playwright/test' );

const baseURL = process.env.E2E_BASE_URL || 'http://localhost:8888';

module.exports = defineConfig(
	{
		testDir: './tests/e2e',
		fullyParallel: false,
		forbidOnly: ! ! process.env.CI,
		retries: process.env.CI ? 1 : 0,
		workers: 1,
		reporter: process.env.CI ? 'github' : 'list',
		timeout: 180000,
		expect: {
			timeout: 30000,
		},
		use: {
			baseURL,
			trace: 'on-first-retry',
			screenshot: 'only-on-failure',
			video: 'retain-on-failure',
			actionTimeout: 30000,
			navigationTimeout: 60000,
		},
		projects: [
			{
				name: 'chromium',
				use: { ...devices[ 'Desktop Chrome' ] },
		},
			],
	}
);
