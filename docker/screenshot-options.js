export const HOST_DEFAULT_BASE_URL = 'http://localhost:8000';
export const DEFAULT_OUTPUT_DIR = './screenshots';

export function resolveScreenshotOptions(argv = process.argv, env = process.env) {
    return {
        baseUrl: argv[2] || env.APP_URL || HOST_DEFAULT_BASE_URL,
        outputDir: argv[3] || env.OUTPUT_DIR || DEFAULT_OUTPUT_DIR,
    };
}
