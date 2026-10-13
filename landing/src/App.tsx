import { MotionConfig } from 'motion/react';
import { Nav } from './components/nav';
import { AppDownload } from './sections/app-download';
import { Footer } from './sections/footer';
import { Hero } from './sections/hero';
import { Insights } from './sections/insights';
import { Lifecycle } from './sections/lifecycle';

export default function App() {
    return (
        <MotionConfig reducedMotion="user">
            <a
                href="#lifecycle"
                className="sr-only z-[60] rounded-full bg-ink px-4 py-2 text-white focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
            >
                Skip to content
            </a>
            <Nav />
            <main>
                <Hero />
                <Lifecycle />
                <Insights />
                <AppDownload />
            </main>
            <Footer />
        </MotionConfig>
    );
}
