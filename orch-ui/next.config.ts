import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  images: {
    remotePatterns: [
      {
        protocol: "https",
        hostname: "pub-c86dcb094ea14d3887ecb8d519b16bab.r2.dev"
      }
    ]
  }
};

export default nextConfig;
