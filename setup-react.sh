#!/bin/bash

# Setup React for WUC Portal
echo "Setting up React for WUC Portal..."

# Install dependencies
echo "Installing dependencies..."
npm install

# Build the React bundle
echo "Building React components..."
npm run build

echo "Setup complete!"
echo "To run development mode with auto-rebuilding, use: npm run dev"
