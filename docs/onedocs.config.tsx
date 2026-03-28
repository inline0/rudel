import { defineConfig } from "onedocs/config";
import { Box, Globe, Database, GitBranch, Camera, Shield, Terminal, Bot } from "lucide-react";
import { HeroLeft } from "./src/components/hero-left";

const iconClass = "h-5 w-5 text-fd-primary";

export default defineConfig({
  title: "Rudel",
  description: "The WordPress isolation layer for sandboxes and multi-tenant apps.",
  logo: {
    light: "/logo-light.svg",
    dark: "/logo-dark.svg",
  },
  icon: { light: "/icon.png", dark: "/icon-dark.png" },
  nav: {
    github: "inline0/rudel",
  },
  footer: {
    links: [{ label: "Inline0.com", href: "https://inline0.com" }],
  },
  homepage: {
    hero: {
      left: HeroLeft,
    },
    features: [
      {
        title: "Sandboxes",
        description:
          "Disposable environments for development, testing, and AI agents. Create and destroy in seconds.",
        icon: <Box className={iconClass} />,
      },
      {
        title: "Multi-Tenant Apps",
        description:
          "Permanent domain-routed sites on one WordPress install. No multisite overhead.",
        icon: <Globe className={iconClass} />,
      },
      {
        title: "Three Engines",
        description:
          "MySQL for compatibility, SQLite for portability, multisite sub-site for network environments.",
        icon: <Database className={iconClass} />,
      },
      {
        title: "GitHub Integration",
        description:
          "Push changes and create PRs via the GitHub API. No git binary needed.",
        icon: <GitBranch className={iconClass} />,
      },
      {
        title: "Snapshots",
        description:
          "Point-in-time snapshots with instant restore. Rollback any environment in seconds.",
        icon: <Camera className={iconClass} />,
      },
      {
        title: "Full Isolation",
        description:
          "Isolated databases, content, auth salts, object cache, and email per environment.",
        icon: <Shield className={iconClass} />,
      },
      {
        title: "WP-CLI & PHP API",
        description:
          "Complete CLI and programmatic API. Everything through Rudel\\Rudel.",
        icon: <Terminal className={iconClass} />,
      },
      {
        title: "Agent Ready",
        description:
          "Built for AI coding agents with scoped WP-CLI, error logging, and CLAUDE.md per environment.",
        icon: <Bot className={iconClass} />,
      },
    ],
  },
});
