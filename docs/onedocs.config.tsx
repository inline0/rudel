import { defineConfig } from "onedocs/config";
import { Box, Database, Camera, Upload, Clock, Terminal, Bot, Layers } from "lucide-react";
import { HeroLeft } from "./src/components/hero-left";

const iconClass = "h-5 w-5 text-fd-primary";

export default defineConfig({
  title: "Rudel",
  description: "WordPress sandboxes powered by SQLite.",
  logo: {
    light: "/logo-light.svg",
    dark: "/logo-dark.svg",
  },
  icon: "/icon.png",
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
          "Isolated WordPress environments with their own SQLite database and wp-content directory.",
        icon: <Box className={iconClass} />,
      },
      {
        title: "Database Cloning",
        description:
          "Clone your host MySQL database into a sandbox SQLite database with automatic URL rewriting.",
        icon: <Database className={iconClass} />,
      },
      {
        title: "Snapshots",
        description:
          "Point-in-time snapshots for any sandbox. Restore instantly.",
        icon: <Camera className={iconClass} />,
      },
      {
        title: "Export & Import",
        description:
          "Package sandboxes as zip archives. Share and deploy anywhere.",
        icon: <Upload className={iconClass} />,
      },
      {
        title: "Auto Cleanup",
        description:
          "Configurable expiry and automatic cleanup of stale sandboxes.",
        icon: <Clock className={iconClass} />,
      },
      {
        title: "WP-CLI",
        description: "Full CLI interface for all sandbox operations.",
        icon: <Terminal className={iconClass} />,
      },
      {
        title: "Templates",
        description:
          "Save sandboxes as reusable templates for rapid provisioning of new environments.",
        icon: <Layers className={iconClass} />,
      },
      {
        title: "Agent Ready",
        description:
          "Built for AI coding agents with scoped WP-CLI and CLAUDE.md support per sandbox.",
        icon: <Bot className={iconClass} />,
      },
    ],
  },
});
