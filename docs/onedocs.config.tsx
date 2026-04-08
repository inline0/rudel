import { defineConfig } from "onedocs/config";
import { Box, Globe, Database, GitBranch, Camera, Shield, Terminal, Bot } from "lucide-react";
import { HeroLeft } from "./src/components/hero-left";

const iconClass = "h-5 w-5 text-fd-primary";

export default defineConfig({
  title: "Rudel",
  description: "WordPress environment orchestration on top of subdomain multisite.",
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
          "Disposable multisite sites for development, testing, and AI agents.",
        icon: <Box className={iconClass} />,
      },
      {
        title: "Apps",
        description:
          "Long-lived multisite sites with backups, deploys, and rollback history.",
        icon: <Globe className={iconClass} />,
      },
      {
        title: "Multisite Runtime",
        description:
          "One runtime model: real subdomain multisite sites for every environment.",
        icon: <Database className={iconClass} />,
      },
      {
        title: "Git Worktrees",
        description:
          "Built-in PHP-native Git clone, push, and worktree flows with no host git binary required.",
        icon: <GitBranch className={iconClass} />,
      },
      {
        title: "Snapshots & Backups",
        description:
          "Snapshots for sandboxes, backups for apps, and recovery built into the workflow.",
        icon: <Camera className={iconClass} />,
      },
      {
        title: "Real Site Isolation",
        description:
          "Per-site tables, native multisite uploads, isolated content, salts, cache, and email policy.",
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
