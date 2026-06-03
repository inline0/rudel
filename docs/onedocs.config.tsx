import { defineConfig } from "onedocs/config";
import { Box, Database, GitBranch, Camera, Shield, Terminal, Bot, Route } from "lucide-react";
import { HeroLeft } from "./src/components/hero-left";

const iconClass = "h-5 w-5 text-fd-primary";

export default defineConfig({
  title: "Rudel",
  description: "Request-selected WordPress overlay environments for sandboxes.",
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
          "Disposable overlay environments for development, testing, and AI agents.",
        icon: <Box className={iconClass} />,
      },
      {
        title: "Request Selection",
        description:
          "Route one request into one sandbox with trusted headers, cookies, or CLI context.",
        icon: <Route className={iconClass} />,
      },
      {
        title: "Overlay Runtime",
        description:
          "One runtime model: cloned table prefixes plus copied active themes.",
        icon: <Database className={iconClass} />,
      },
      {
        title: "Git Worktrees",
        description:
          "Built-in PHP-native Git clone, push, and worktree flows with no host git binary required.",
        icon: <GitBranch className={iconClass} />,
      },
      {
        title: "Snapshots",
        description:
          "Recovery points for disposable environments without a second runtime source of truth.",
        icon: <Camera className={iconClass} />,
      },
      {
        title: "Request Isolation",
        description:
          "Per-request table prefixes, active theme roots, salts, cache, and email policy.",
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
          "Built for isolated WordPress environments with scoped WP-CLI and error logging.",
        icon: <Bot className={iconClass} />,
      },
    ],
  },
});
