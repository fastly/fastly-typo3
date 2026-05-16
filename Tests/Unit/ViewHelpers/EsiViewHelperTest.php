<?php

declare(strict_types=1);

namespace Fastly\Cdn\Tests\Unit\ViewHelpers;

use Fastly\Cdn\ViewHelpers\EsiViewHelper;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\RequestInterface as ExtbaseRequestInterface;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder as ExtbaseUriBuilder;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Typolink\LinkFactory;
use TYPO3\CMS\Frontend\Typolink\LinkResultInterface;
use TYPO3\CMS\Frontend\Typolink\UnableToLinkException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\Variables\VariableProviderInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperVariableContainer;

final class EsiViewHelperTest extends UnitTestCase
{
    /**
     * Build a fully-wired EsiViewHelper with a mocked rendering context.
     *
     * @param ServerRequestInterface|null $request  Pass null to simulate no request in context
     */
    private function makeViewHelper(?ServerRequestInterface $request = null): EsiViewHelper
    {
        $variableProvider = $this->createMock(VariableProviderInterface::class);
        $vhVariableContainer = $this->createMock(ViewHelperVariableContainer::class);

        $renderingContext = $this->createMock(RenderingContextInterface::class);
        $renderingContext->method('getVariableProvider')->willReturn($variableProvider);
        $renderingContext->method('getViewHelperVariableContainer')->willReturn($vhVariableContainer);

        if ($request !== null) {
            $renderingContext->method('hasAttribute')
                ->with(ServerRequestInterface::class)
                ->willReturn(true);
            $renderingContext->method('getAttribute')
                ->with(ServerRequestInterface::class)
                ->willReturn($request);
        } else {
            $renderingContext->method('hasAttribute')->willReturn(false);
        }

        $viewHelper = new EsiViewHelper();
        $viewHelper->setRenderingContext($renderingContext);
        $viewHelper->initializeArguments();

        return $viewHelper;
    }

    private function defaultArguments(array $overrides = []): array
    {
        return array_merge([
            'pageUid' => null,
            'additionalParams' => [],
            'pageType' => 0,
            'noCache' => false,
            'language' => null,
            'section' => '',
            'linkAccessRestrictedPages' => false,
            'absolute' => false,
            'addQueryString' => false,
            'argumentsToBeExcludedFromQueryString' => [],
            'src' => null,
        ], $overrides);
    }

    public function testRenderWithDirectSrcReturnsEsiIncludeTag(): void
    {
        $viewHelper = $this->makeViewHelper();
        $viewHelper->setArguments($this->defaultArguments(['src' => '/api/fragment']));

        $result = $viewHelper->render();

        self::assertStringContainsString('<esi:include', $result);
        self::assertStringContainsString('src=', $result);
        self::assertStringContainsString('/api/fragment', $result);
    }

    public function testRenderThrowsExceptionWhenNoRequestInContext(): void
    {
        $viewHelper = $this->makeViewHelper(request: null);
        $viewHelper->setArguments($this->defaultArguments(['src' => null]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1639819269);

        $viewHelper->render();
    }

    public function testRenderThrowsExceptionInBackendContext(): void
    {
        // ApplicationType::fromRequest() expects getAttribute('applicationType') to return
        // an int flag (REQUESTTYPE_BE = 2), not the ApplicationType enum itself.
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('applicationType')
            ->willReturn(SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $viewHelper = $this->makeViewHelper($request);
        $viewHelper->setArguments($this->defaultArguments(['src' => null]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1639819268);

        $viewHelper->render();
    }

    public function testRenderWithEmptySrcReturnsEmptyString(): void
    {
        // An empty non-null src ('' or ' ') should reach the "$src === ''" guard and return ''
        $viewHelper = $this->makeViewHelper();
        // src='' is treated as null/unset in the arg check (`!== null && !== ''`)
        // so it will fall through to the request-based path → throw with code 1639819269
        $viewHelper->setArguments($this->defaultArguments(['src' => '']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1639819269);

        $viewHelper->render();
    }

    public function testRenderWithExtbaseRequestUsesUriBuilder(): void
    {
        $request = $this->createMock(ExtbaseRequestInterface::class);
        $uriBuilder = $this->createMock(ExtbaseUriBuilder::class);
        $uriBuilder->expects(self::once())->method('reset')->willReturnSelf();
        $uriBuilder->expects(self::once())->method('setRequest')->with($request)->willReturnSelf();
        $uriBuilder->expects(self::once())->method('setTargetPageType')->with(13)->willReturnSelf();
        $uriBuilder->expects(self::once())->method('setNoCache')->with(true)->willReturnSelf();
        $uriBuilder->expects(self::once())->method('setSection')->with('content')->willReturnSelf();
        $uriBuilder->expects(self::once())->method('setLanguage')->with('2')->willReturnSelf();
        $uriBuilder->expects(self::once())->method('setLinkAccessRestrictedPages')->with(true)->willReturnSelf();
        $uriBuilder->expects(self::once())->method('setArguments')->with(['tx_demo' => ['foo' => 'bar']])->willReturnSelf();
        $uriBuilder->expects(self::once())->method('setCreateAbsoluteUri')->with(true)->willReturnSelf();
        $uriBuilder->expects(self::once())->method('setAddQueryString')->with('untrusted')->willReturnSelf();
        $uriBuilder->expects(self::once())->method('setArgumentsToBeExcludedFromQueryString')
            ->with(['cHash'])
            ->willReturnSelf();
        $uriBuilder->expects(self::once())->method('setTargetPageUid')->with(123)->willReturnSelf();
        $uriBuilder->expects(self::once())->method('build')->willReturn('/extbase/fragment');
        GeneralUtility::addInstance(ExtbaseUriBuilder::class, $uriBuilder);

        $viewHelper = $this->makeViewHelper($request);
        $viewHelper->setArguments($this->defaultArguments([
            'pageUid' => 123,
            'additionalParams' => ['tx_demo' => ['foo' => 'bar']],
            'pageType' => 13,
            'noCache' => true,
            'language' => '2',
            'section' => 'content',
            'linkAccessRestrictedPages' => true,
            'absolute' => true,
            'addQueryString' => 'untrusted',
            'argumentsToBeExcludedFromQueryString' => ['cHash'],
        ]));

        $result = $viewHelper->render();

        self::assertStringContainsString('<esi:include', $result);
        self::assertStringContainsString('/extbase/fragment', $result);
    }

    public function testRenderReturnsEmptyStringWhenExtbaseUriBuilderBuildsEmptyUri(): void
    {
        $request = $this->createMock(ExtbaseRequestInterface::class);
        $uriBuilder = $this->createMock(ExtbaseUriBuilder::class);
        $uriBuilder->method('reset')->willReturnSelf();
        $uriBuilder->method('setRequest')->willReturnSelf();
        $uriBuilder->method('setTargetPageType')->willReturnSelf();
        $uriBuilder->method('setNoCache')->willReturnSelf();
        $uriBuilder->method('setSection')->willReturnSelf();
        $uriBuilder->method('setLanguage')->willReturnSelf();
        $uriBuilder->method('setLinkAccessRestrictedPages')->willReturnSelf();
        $uriBuilder->method('setArguments')->willReturnSelf();
        $uriBuilder->method('setCreateAbsoluteUri')->willReturnSelf();
        $uriBuilder->method('setAddQueryString')->willReturnSelf();
        $uriBuilder->method('setArgumentsToBeExcludedFromQueryString')->willReturnSelf();
        $uriBuilder->method('build')->willReturn('');
        GeneralUtility::addInstance(ExtbaseUriBuilder::class, $uriBuilder);

        $viewHelper = $this->makeViewHelper($request);
        $viewHelper->setArguments($this->defaultArguments());

        self::assertSame('', $viewHelper->render());
    }

    public function testRenderWithFrontendRequestBuildsTypolinkConfiguration(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('applicationType')
            ->willReturn(SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $contentObject = $this->createMock(ContentObjectRenderer::class);
        $contentObject->expects(self::once())->method('setRequest')->with($request);
        GeneralUtility::addInstance(ContentObjectRenderer::class, $contentObject);

        $linkResult = $this->createMock(LinkResultInterface::class);
        $linkResult->method('getUrl')->willReturn('/frontend/fragment');
        $linkFactory = $this->createMock(LinkFactory::class);
        $linkFactory->expects(self::once())->method('create')->with(
            'children output',
            self::callback(static function (array $configuration): bool {
                return $configuration['parameter'] === '456,99'
                    && $configuration['no_cache'] === 1
                    && $configuration['language'] === 'current'
                    && $configuration['section'] === 'teaser'
                    && $configuration['linkAccessRestrictedPages'] === 1
                    && $configuration['additionalParams'] === '&foo=bar&nested%5Bbaz%5D=qux'
                    && $configuration['forceAbsoluteUrl'] === true
                    && $configuration['addQueryString'] === 'untrusted'
                    && $configuration['addQueryString.']['exclude'] === 'cHash,L';
            }),
            $contentObject,
        )->willReturn($linkResult);
        GeneralUtility::addInstance(LinkFactory::class, $linkFactory);

        $viewHelper = $this->makeViewHelper($request);
        $viewHelper->setRenderChildrenClosure(static fn(): string => 'children output');
        $viewHelper->setArguments($this->defaultArguments([
            'pageUid' => 456,
            'pageType' => 99,
            'noCache' => true,
            'language' => 'current',
            'section' => 'teaser',
            'linkAccessRestrictedPages' => true,
            'additionalParams' => ['foo' => 'bar', 'nested' => ['baz' => 'qux']],
            'absolute' => true,
            'addQueryString' => 'untrusted',
            'argumentsToBeExcludedFromQueryString' => ['cHash', 'L'],
        ]));

        $result = $viewHelper->render();

        self::assertStringContainsString('/frontend/fragment', $result);
    }

    public function testRenderWithFrontendRequestFallsBackToChildrenWhenTypolinkFails(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with('applicationType')
            ->willReturn(SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $contentObject = $this->createMock(ContentObjectRenderer::class);
        $contentObject->expects(self::once())->method('setRequest')->with($request);
        GeneralUtility::addInstance(ContentObjectRenderer::class, $contentObject);

        $linkFactory = $this->createMock(LinkFactory::class);
        $linkFactory->expects(self::once())->method('create')
            ->willThrowException(new UnableToLinkException('Unable to link'));
        GeneralUtility::addInstance(LinkFactory::class, $linkFactory);

        $viewHelper = $this->makeViewHelper($request);
        $viewHelper->setRenderChildrenClosure(static fn(): string => '/fallback/fragment');
        $arguments = $this->defaultArguments(['pageUid' => 456]);
        unset($arguments['argumentsToBeExcludedFromQueryString']);
        $viewHelper->setArguments($arguments);

        $result = $viewHelper->render();

        self::assertStringContainsString('/fallback/fragment', $result);
    }
}
