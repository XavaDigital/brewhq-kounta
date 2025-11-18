/**
 * Build script for BrewHQ Kounta plugin
 * Creates a clean deployment package in the 'dist' folder
 */

const fs = require('fs');
const path = require('path');

// Configuration
const BUILD_DIR = 'dist';
const PLUGIN_NAME = 'brewhq-kounta';
const OUTPUT_DIR = path.join(BUILD_DIR, PLUGIN_NAME);

// Files and directories to include in the build
const INCLUDE = [
    'brewhq-kounta.php',
    'LICENSE',
    'Licensing',
    'admin',
    'assets',
    'includes'
];

// Files and directories to exclude (even if inside included directories)
const EXCLUDE_PATTERNS = [
    /^\.git/,
    /^\.gitignore/,
    /^node_modules/,
    /^dist/,
    /^documentation/,
    /\.md$/i,  // All markdown files
    /^docker-compose\.yml$/,
    /^package\.json$/,
    /^package-lock\.json$/,
    /^build\.js$/,
    /^setup-wordpress\.(ps1|sh)$/,
    /\.DS_Store$/,
    /Thumbs\.db$/,
    /desktop\.ini$/
];

/**
 * Check if a file/directory should be excluded
 */
function shouldExclude(filePath) {
    const relativePath = path.relative(process.cwd(), filePath);
    return EXCLUDE_PATTERNS.some(pattern => pattern.test(relativePath));
}

/**
 * Recursively copy directory
 */
function copyDirectory(src, dest) {
    // Create destination directory
    if (!fs.existsSync(dest)) {
        fs.mkdirSync(dest, { recursive: true });
    }

    // Read source directory
    const entries = fs.readdirSync(src, { withFileTypes: true });

    for (const entry of entries) {
        const srcPath = path.join(src, entry.name);
        const destPath = path.join(dest, entry.name);

        // Skip excluded files
        if (shouldExclude(srcPath)) {
            console.log(`  ⊗ Skipping: ${path.relative(process.cwd(), srcPath)}`);
            continue;
        }

        if (entry.isDirectory()) {
            copyDirectory(srcPath, destPath);
        } else {
            fs.copyFileSync(srcPath, destPath);
            console.log(`  ✓ Copied: ${path.relative(process.cwd(), srcPath)}`);
        }
    }
}

/**
 * Remove directory recursively
 */
function removeDirectory(dir) {
    if (fs.existsSync(dir)) {
        fs.rmSync(dir, { recursive: true, force: true });
    }
}

/**
 * Get directory size in MB
 */
function getDirectorySize(dir) {
    let size = 0;
    
    function calculateSize(dirPath) {
        const entries = fs.readdirSync(dirPath, { withFileTypes: true });
        for (const entry of entries) {
            const fullPath = path.join(dirPath, entry.name);
            if (entry.isDirectory()) {
                calculateSize(fullPath);
            } else {
                size += fs.statSync(fullPath).size;
            }
        }
    }
    
    calculateSize(dir);
    return (size / 1024 / 1024).toFixed(2);
}

/**
 * Count files in directory
 */
function countFiles(dir) {
    let count = 0;
    
    function count_recursive(dirPath) {
        const entries = fs.readdirSync(dirPath, { withFileTypes: true });
        for (const entry of entries) {
            const fullPath = path.join(dirPath, entry.name);
            if (entry.isDirectory()) {
                count_recursive(fullPath);
            } else {
                count++;
            }
        }
    }
    
    count_recursive(dir);
    return count;
}

/**
 * Main build function
 */
function build() {
    console.log('🚀 Building BrewHQ Kounta plugin...\n');

    // Clean previous build
    console.log('🧹 Cleaning previous build...');
    removeDirectory(BUILD_DIR);
    console.log('  ✓ Cleaned\n');

    // Create output directory
    console.log('📁 Creating build directory...');
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
    console.log('  ✓ Created\n');

    // Copy files
    console.log('📦 Copying plugin files...');
    for (const item of INCLUDE) {
        const srcPath = path.join(process.cwd(), item);
        const destPath = path.join(OUTPUT_DIR, item);

        if (!fs.existsSync(srcPath)) {
            console.log(`  ⚠ Warning: ${item} not found, skipping`);
            continue;
        }

        const stat = fs.statSync(srcPath);
        if (stat.isDirectory()) {
            copyDirectory(srcPath, destPath);
        } else {
            fs.copyFileSync(srcPath, destPath);
            console.log(`  ✓ Copied: ${item}`);
        }
    }

    // Build summary
    const fileCount = countFiles(OUTPUT_DIR);
    const size = getDirectorySize(OUTPUT_DIR);

    console.log('\n✅ Build complete!\n');
    console.log('📊 Build Summary:');
    console.log(`  Location: ${OUTPUT_DIR}`);
    console.log(`  Files: ${fileCount}`);
    console.log(`  Size: ${size} MB`);
    console.log('\n📦 Ready to deploy!');
    console.log(`  Upload the contents of '${BUILD_DIR}/${PLUGIN_NAME}' to your WordPress plugins directory.`);
}

// Run build
try {
    build();
} catch (error) {
    console.error('❌ Build failed:', error.message);
    process.exit(1);
}

