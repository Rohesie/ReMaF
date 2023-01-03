<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\NotBlank;


class UserDataType extends AbstractType {

	public function configureOptions(OptionsResolver $resolver) {
		$resolver->setDefaults([
			'translation_domain' => 'core',
			'username' => null,
			'email' => null,
			'display' => null,
		]);
	}

	public function buildForm(FormBuilderInterface $builder, array $options) {
		$builder->add('username', TextType::class, [
			'label' => 'form.username.username',
			'required' => false,
			'attr' => [
				'id' => 'username'
			],
			'constraints' => [
				new Regex([
					'pattern' => '/^[a-zA-Z0-9 \-_]*$/',
					'message' => 'form.username.help',
				]),
			],
			'data' => $options['username'],
		]);
		$builder->add('display_name', TextType::class, [
			'label' => 'form.register.display',
			'required' => false,
			'attr' => [
				'autocomplete' => 'off',
			],
			'constraints' => [
				new Regex([
					'pattern' => '/^[a-zA-Z0-9 \-_]*$/',
					'message' => 'form.register.displayhelp',
				]),
			],
			'data' => $options['display'],
		]);
		if ($options['gm']) {
			$builder->add('gm_name', TextType::class, [
				'label' => 'form.gmname',
				'attr' => [
					'title' => 'form.help.gmname',
					'class'=>'tt_bot'
				],
				'data' => $options['gm_name'],
			]);
		} else {
			$builder->add('gm_name', HiddenType::class, array('data' => null, 'required'=>false));
		}
		if ($options['admin']) {
			$builder->add('public_admin', TextType::class, [
				'label' => 'form.publicadmin',
				'attr' => [
					'title' => 'form.help.publicadmin',
					'class'=>'tt_bot'
				],
				'data' => $options['public_admin'],
			]);
		} else {
			$builder->add('public_admin', HiddenType::class, array('data' => null, 'required'=>false));
		}
		$builder->add('email', EmailType::class, [
			'label' => 'form.email.email',
			'required' => false,
			'attr' => [
				'autocomplete' => 'off',
			],
			'constraints' => [
				new Email([
					'message' => 'form.email.help',
				]),
			],
			'data' => $options['email'],
		]);
		$builder->add('plainPassword', RepeatedType::class, [
			'type' => PasswordType::class,
			'options' => ['attr' => ['password-field']],
			'required' => false,
			'attr' => [
				'autocomplete' => 'off',
			],
			'invalid_message' => 'form.password.nomatch',
			'first_options' => ['label' => 'form.password.password'],
			'second_options' => ['label' => 'form.password.confirm'],
			'constraints' => [
				new Length([
					'min' => 8,
					'minMessage' => 'form.password.help',
					# max length allowed by Symfony for security reasons
					'max' => 4096,
				]),
			],
		]);
		$builder->add('public', CheckboxType::class, [
			'label' => 'form.public',
			'attr' => [
				'title'=>'form.help.public',
				'class'=>'tt_bot'
			],
			'data' => $options['public'],
		]);
		$builder->add('show_patronage', CheckboxType::class, array(
			'label' => 'form.patronage',
			'required' => false,
			'attr' => [
				'title'=>'form.help.patronage',
				'class'=>'tt_bot'
			],
			'data' => $options['show_patronage'],
		));
		$builder->add('text', TextareaType::class, [
			'label'=>'form.profile',
			'data'=>$options['text'],
			'mapped'=>false,
			'required'=>false,
			'attr' => [
				'title'=>'form.help.profile',
				'class'=>'tt_bot'
			]
		]);
		$builder->add('submit', SubmitType::class, [
			'label' => 'form.submit'
		]);
	}
}
